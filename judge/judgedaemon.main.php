<?php
/**
 * Request a yet unjudged submission from the database, judge it, and pass
 * the results back in to the database.
 *
 * $Id$
 *
 * Part of the DOMjudge Programming Contest Jury System and licenced
 * under the GNU GPL. See README and COPYING for details.
 */
if ( isset($_SERVER['REMOTE_ADDR']) ) die ("Commandline use only");

require(ETCDIR . '/judgehost-config.php');

// Set environment variables for passing path configuration to called programs
putenv('DJ_BINDIR='      . BINDIR);
putenv('DJ_ETCDIR='      . ETCDIR);
putenv('DJ_JUDGEDIR='    . JUDGEDIR);
putenv('DJ_LIBDIR='      . LIBDIR);
putenv('DJ_LIBJUDGEDIR=' . LIBJUDGEDIR);
putenv('DJ_LOGDIR='      . LOGDIR);

// Set other configuration variables for called programs
putenv('RUNUSER='       . RUNUSER);
putenv('USE_CHROOT='    . (USE_CHROOT ? '1' : ''));
putenv('CHROOT_SCRIPT=' . CHROOT_SCRIPT);
putenv('COMPILETIME='   . COMPILETIME);
putenv('MEMLIMIT='      . MEMLIMIT);
putenv('FILELIMIT='     . FILELIMIT);
putenv('PROCLIMIT='     . PROCLIMIT);

foreach ( $EXITCODES as $code => $name ) {
	$var = 'E_' . strtoupper(str_replace('-','_',$name));
	putenv($var . '=' . $code);
}

$waittime = 5;

$myhost = trim(`hostname | cut -d . -f 1`);

define ('SCRIPT_ID', 'judgedaemon');
define ('LOGFILE', LOGDIR.'/judge.'.$myhost.'.log');

require(LIBDIR . '/init.php');

setup_database_connection('jury');

$verbose = LOG_INFO;

system("pgrep -u ".RUNUSER, $retval);
if ($retval == 0) {
	error("Still some processes by ".RUNUSER." found, aborting");
}
if ($retval != 1) {
	error("Error while checking processes for user " . RUNUSER);
}

logmsg(LOG_NOTICE, "Judge started on $myhost [DOMjudge/".DOMJUDGE_VERSION."]");

// Retrieve hostname and check database for judgehost entry
$row = $DB->q('MAYBETUPLE SELECT * FROM judgehost WHERE hostname = %s', $myhost);
if ( ! $row ) {
	error("No database entry found for me ($myhost), exiting");
}
$myhost = $row['hostname'];

// Create directory where to test submissions
$tempdirpath = JUDGEDIR . "/$myhost";
system("mkdir -p $tempdirpath/testcase", $retval);
if ( $retval != 0 ) error("Could not create $tempdirpath");

$waiting = FALSE;
$active = TRUE;
$cid = null;

// Tick use required between PHP 4.3.0 and 5.3.0 for handling signals,
// must be declared globally.
if ( version_compare(PHP_VERSION, '5.3', '<' ) ) {
	declare(ticks = 1);
}
initsignals();

// Constantly check database for unjudged submissions
while ( TRUE ) {

	// Check whether we have received an exit signal
	if ( function_exists('pcntl_signal_dispatch') ) pcntl_signal_dispatch();
	if ( $exitsignalled ) {
		logmsg(LOG_NOTICE, "Received signal, exiting.");
		exit;
	}
	
	// Check that this judge is active, else wait and check again later
	$row = $DB->q('TUPLE SELECT * FROM judgehost WHERE hostname = %s', $myhost);
	$DB->q('UPDATE LOW_PRIORITY judgehost SET polltime = NOW()
	       WHERE hostname = %s', $myhost);
	if ( $row['active'] != 1 ) {
		if ( $active ) {
			logmsg(LOG_NOTICE, "Not active, waiting for activation...");
			$active = FALSE;
		}
		sleep($waittime);
		continue;
	}
	if ( ! $active ) {
		logmsg(LOG_INFO, "Activated, checking queue...");
		$active = TRUE;
		$waiting = FALSE;
	}

	$contdata = getCurContest(TRUE);
	$newcid = $contdata['cid'];
	$oldcid = $cid;
	if ( $oldcid !== $newcid ) {
		logmsg(LOG_NOTICE, "Contest has changed from " .
		       (isset($oldcid) ? "c$oldcid" : "none" ) . " to " .
		       (isset($newcid) ? "c$newcid" : "none" ) );
		$cid = $newcid;
	}
	
	// we have to check for the judgability of problems/languages this way,
	// because we use an UPDATE below where joining is not possible.
	$probs = $DB->q('COLUMN SELECT probid FROM problem WHERE allow_judge = 1');
	if( count($probs) == 0 ) {
		logmsg(LOG_NOTICE, "No judgable problems, waiting...");
		sleep($waittime);
		continue;
	}
	$judgable_prob = array_unique(array_values($probs));
	$langs = $DB->q('COLUMN SELECT langid FROM language WHERE allow_judge = 1');
	if( count($langs) == 0 ) {
		logmsg(LOG_NOTICE, "No judgable languages, waiting...");
		sleep($waittime);
		continue;
	}
	$judgable_lang = array_unique(array_values($langs));

	// First, use a select to see whether there are any judgeable
	// submissions. This query is query-cacheable, and doing a select
	// first prevents a write-lock on the submission table if nothing is
	// to be judged, and also prevents throwing away the query cache every
	// single time
	$numopen = $DB->q('VALUE SELECT COUNT(*) FROM submission
	                   WHERE judgehost IS NULL AND cid = %i AND langid IN (%As)
	                   AND probid IN (%As) AND submittime < %s AND valid = 1',
	                  $cid, $judgable_lang, $judgable_prob, $contdata['endtime']);

	$numupd = 0;
	if ($numopen) {
		// Generate (unique) random string to mark submission to be judged
		list($usec, $sec) = explode(" ", microtime());
		$mark = $myhost.'@'.($sec+$usec).'#'.uniqid( mt_rand(), true );

		// update exactly one submission with our random string
		// Note: this might still return 0 if another judgehost beat
		// us to it
		$numupd = $DB->q('RETURNAFFECTED UPDATE submission
				  SET judgehost = %s, judgemark = %s
				  WHERE judgehost IS NULL AND cid = %i AND langid IN (%As)
				  AND probid IN (%As) AND submittime < %s AND valid = 1
				  LIMIT 1',
				 $myhost, $mark, $cid, $judgable_lang, $judgable_prob,
				 $contdata['endtime']);
	}

	// nothing updated -> no open submissions
	if ( $numupd == 0 ) {
		if ( ! $waiting ) {
			logmsg(LOG_INFO, "No submissions in queue, waiting...");
			$waiting = TRUE;
		}
		sleep($waittime);
		continue;
	}

	$waiting = FALSE;

	// get maximum runtime, source code and other parameters
	$row = $DB->q('TUPLE SELECT CEILING(time_factor*timelimit) AS maxruntime,
	               s.submitid, s.sourcecode, s.langid, s.teamid, s.probid,
	               p.special_run, p.special_compare, l.extension
	               FROM submission s, problem p, language l
	               WHERE s.probid = p.probid AND s.langid = l.langid AND
	               judgemark = %s AND judgehost = %s', $mark, $myhost);

	logmsg(LOG_NOTICE, "Judging submission s$row[submitid] ".
	       "($row[teamid]/$row[probid]/$row[langid])...");

	// update the judging table with our ID and the starttime
	$judgingid = $DB->q('RETURNID INSERT INTO judging (submitid,cid,starttime,judgehost)
	                     VALUES (%i,%i,%s,%s)', $row['submitid'], $cid, now(), $myhost);

	// create tempdir for tempfiles
	$tempdir = "$tempdirpath/c$cid-s$row[submitid]-j$judgingid";
	
	logmsg(LOG_INFO, "Working directory: $tempdir");

	system("mkdir -p $tempdir", $retval);
	if ( $retval != 0 ) error("Could not create $tempdir");

	// dump the source code in a tempfile
	// :KLUDGE: In older versions, test_solution.sh creates a temporary copy
	// of the original source file. Since this version doesn't use real source
	// files (source code is submitted to the database), we choose to put the
	// original source code in another temporary file for now, which is then
	// copied by test_solution.sh.
	$tempsrcfile = "$tempdir/source.pulled.$row[extension]";
	// :NOTE: in PHP5, one could use file_put_contents().
	$tempsrchandle = @fopen($tempsrcfile, 'w');
	if ($tempsrchandle === FALSE) error("Could not create $tempsrcfile");
	fwrite($tempsrchandle, $row['sourcecode']);
	fclose($tempsrchandle);
	unset($row['sourcecode']);


	// Fetch testcases from database.
	// We currently support exactly one row per problem.
	$tcdata = $DB->q("MAYBETUPLE SELECT id, md5sum_input, md5sum_output FROM testcase WHERE probid = %s",
		$row['probid']);
	if ( empty($tcdata) ) {
		error("No testcase found for problem " . $row['probid']);
	}

	// Get both in- and output files, only if we didn't have them already.
	// FIXME: make these files not readable by the compiling process since it doesn't
	// need to read them.
	foreach(array('input','output') as $inout) {
		$tcfile = "$tempdirpath/testcase/testcase.$inout." . $row['probid'] . "." . $tcdata['id'] . "." . $tcdata['md5sum_'.$inout];
		if ( !file_exists($tcfile) ) {
			$content = $DB->q("VALUE SELECT " . $inout . " FROM testcase WHERE probid = %s",
				$row['probid']);
			$fh = @fopen("$tcfile.new", 'w');
			if ($fh === FALSE) error("Could not create $tcfile.new");
			fwrite($fh, $content);
			fclose($fh);
			unset($content);
			if ( md5_file("$tcfile.new") == $tcdata['md5sum_'.$inout]) {
				rename("$tcfile.new",$tcfile);
			} else {
				error ("File corrupted during download.");
			}
			logmsg(LOG_NOTICE, "Fetched new $inout testcase for problem " . $row['probid']);
		}
		// sanity check
		if ( md5_file($tcfile) != $tcdata['md5sum_' . $inout] ) {
			error("File corrupted: md5sum mismatch: " . $tcfile);
		}
	}

	// do the actual compile-run-test
	system(LIBJUDGEDIR . "/test_solution.sh " .
			"$tempsrcfile $row[langid] " .
			"$tempdirpath/testcase/testcase.input." . $row['probid'] . "." . $tcdata['id'] . "." . $tcdata['md5sum_input'] . ' ' .
			"$tempdirpath/testcase/testcase.output." . $row['probid'] . "." . $tcdata['id'] . "." . $tcdata['md5sum_output'] . ' ' .
		   "$row[maxruntime] $tempdir " .
		   "'$row[special_run]' '$row[special_compare]'",
		$retval);

	// leave the temporary copy for reference
	// what does the exitcode mean?
	if( ! isset($EXITCODES[$retval]) ) {
		beep(BEEP_ERROR);
		error("s$row[submitid] Unknown exitcode from test_solution.sh: $retval");
	}
	$result = $EXITCODES[$retval];

	// Start a transaction. This will provide extra safety if the table type
	// supports it.
	$DB->q('START TRANSACTION');
	// pop the result back into the judging table
	$DB->q('UPDATE judging SET endtime = %s, result = %s,
	        output_compile = %s, output_run = %s, output_diff = %s, output_error = %s
	        WHERE judgingid = %i AND judgehost = %s',
	       now(), $result,
	       getFileContents( $tempdir . '/compile.out' ),
	       getFileContents( $tempdir . '/program.out' ),
	       getFileContents( $tempdir . '/compare.out' ),
	       getFileContents( $tempdir . '/error.out' ),
	       $judgingid, $myhost);

	// recalculate the scoreboard cell (team,problem) after this judging
	calcScoreRow($cid, $row['teamid'], $row['probid']);

	// log to event table if no verification required
	// (case of verification required is handled in www/jury/verify.php)
	if ( ! VERIFICATION_REQUIRED ) {
		$DB->q('INSERT INTO event (eventtime, cid, teamid, langid, probid,
		                           submitid, judgingid, description)
		        VALUES(%s, %i, %s, %s, %s, %i, %i, "problem judged")',
		       now(), $cid, $row['teamid'], $row['langid'], $row['probid'],
		       $row['submitid'], $judgingid);
	}
	
	$DB->q('COMMIT');
	
	// done!
	logmsg(LOG_NOTICE, "Judging s$row[submitid]/j$judgingid finished, result: $result");
	if ( $result == 'correct' ) {
		beep(BEEP_ACCEPT);
	} else {
		beep(BEEP_REJECT);
	}

	// restart the judging loop
}