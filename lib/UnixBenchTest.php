<?php
// Copyright 2014 CloudHarmony Inc.
// 
// Licensed under the Apache License, Version 2.0 (the "License");
// you may not use this file except in compliance with the License.
// You may obtain a copy of the License at
// 
//     http://www.apache.org/licenses/LICENSE-2.0
// 
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.


/**
 * Used to manage UNIX_BENCH testing
 */
require_once(dirname(__FILE__) . '/util.php');
ini_set('memory_limit', '16m');
date_default_timezone_set('UTC');

class UnixBenchTest {
  
  /**
   * name of the file where serializes options should be written to for given 
   * test iteration
   */
  const UNIX_BENCH_TEST_OPTIONS_FILE_NAME = '.options';
  
  /**
   * name of the file unixbench output is written to
   */
  const UNIX_BENCH_TEST_FILE_NAME = 'unixbench.out';
  
  /**
   * name of the file unixbench runtime errors is written to
   */
  const UNIX_BENCH_TEST_ERR_FILE = 'unixbench.err';
  
  /**
   * name of the file unixbench runtime script is written to
   */
  const UNIX_BENCH_TEST_RUN_FILE = 'unixbench.run';
  
  /**
   * name of the file unixbench status is written to
   */
  const UNIX_BENCH_TEST_EXIT_FILE = 'unixbench.status';
  
  /**
   * optional results directory object was instantiated for
   */
  private $dir;
  
  /**
   * run options
   */
  private $options;
  
  
  /**
   * constructor
   * @param string $dir optional results directory object is being instantiated
   * for. If set, runtime parameters will be pulled from the .options file. Do
   * not set when running a test
   */
  public function UnixBenchTest($dir=NULL) {
    $this->dir = $dir;
  }
  
  /**
   * clears the UnixBench results directory
   */
  private function clearResults() {
    exec(sprintf('rm -f %s/results/*', $this->options['unixbench_dir']));
  }
  
  /**
   * writes test results and finalizes testing
   * @return boolean
   */
  private function endTest() {
    $ended = FALSE;
    $dir = $this->options['output'];
    
    // add test stop time
    $this->options['test_stopped'] = date('Y-m-d H:i:s');
    
    // serialize options
    $ofile = sprintf('%s/%s', $dir, self::UNIX_BENCH_TEST_OPTIONS_FILE_NAME);
    if (is_dir($dir) && is_writable($dir)) {
      $fp = fopen($ofile, 'w');
      fwrite($fp, serialize($this->options));
      fclose($fp);
      $ended = TRUE;
    }
    // get log, html and text output files from results directory
    if ($d = dir(sprintf('%s/results', $this->options['unixbench_dir']))) {
      while (FALSE !== ($entry = $d->read())) {
        if (preg_match('/\-/', $entry)) {
          if (preg_match('/log$/', $entry)) exec(sprintf('mv %s/results/%s %s/unixbench.log', $this->options['unixbench_dir'], $entry, $this->options['output']));
          else if (preg_match('/html$/', $entry)) exec(sprintf('mv %s/results/%s %s/unixbench.html', $this->options['unixbench_dir'], $entry, $this->options['output']));
          else exec(sprintf('mv %s/results/%s %s/unixbench.txt', $this->options['unixbench_dir'], $entry, $this->options['output']));
        }
      }
      $d->close();
    }
    
    return $ended;
  }
  
  /**
   * returns results from testing as a hash of key/value pairs
   * @return array
   */
  public function getResults() {
    $results = NULL;
    if (isset($this->dir) && is_dir($this->dir) && file_exists($ofile = sprintf('%s/%s', $this->dir, self::UNIX_BENCH_TEST_FILE_NAME))) {
      foreach($this->getRunOptions() as $key => $val) {
        if (preg_match('/^meta_/', $key) || preg_match('/^test_/', $key)) $results[$key] = $val;
      }
      if ($handle = popen(sprintf('%s/parse.php %s', dirname(__FILE__), $ofile), 'r')) {
        while(!feof($handle) && ($line = fgets($handle))) {
          if (preg_match('/^([a-z][^=]+)=(.*)$/', $line, $m)) $results[$m[1]] = $m[2];
        }
        fclose($handle);
      }
    }
    return $results;
  }
  
  /**
   * returns run options represents as a hash
   * @return array
   */
  public function getRunOptions() {
    if (!isset($this->options)) {
      if ($this->dir) $this->options = self::getSerializedOptions($this->dir);
      else {
        // default run argument values
        $sysInfo = get_sys_info();
        $defaults = array(
          'meta_compute_service' => 'Not Specified',
          'meta_cpu' => $sysInfo['cpu'],
          'meta_instance_id' => 'Not Specified',
          'meta_memory' => $sysInfo['memory_gb'] . ' GB',
          'meta_os' => $sysInfo['os_info'],
          'meta_provider' => 'Not Specified',
          'meta_storage_config' => 'Not Specified',
          'output' => trim(shell_exec('pwd'))
        );
        $opts = array(
          'meta_compute_service:',
          'meta_compute_service_id:',
          'meta_cpu:',
          'meta_instance_id:',
          'meta_memory:',
          'meta_os:',
          'meta_provider:',
          'meta_provider_id:',
          'meta_region:',
          'meta_resource_id:',
          'meta_run_id:',
          'meta_storage_config:',
          'meta_test_id:',
          'output:',
          'v' => 'verbose',
          'unixbench_dir:'
        );
        $this->options = parse_args($opts); 
        foreach($defaults as $key => $val) {
          if (!isset($this->options[$key])) $this->options[$key] = $val;
        } 
      }
    }
    return $this->options;
  }
  
  /**
   * returns options from the serialized file where they are written when a 
   * test completes
   * @param string $dir the directory where results were written to
   * @return array
   */
  public static function getSerializedOptions($dir) {
    return unserialize(file_get_contents(sprintf('%s/%s', $dir, self::UNIX_BENCH_TEST_OPTIONS_FILE_NAME)));
  }
  
  /**
   * initiates unixbench testing. returns TRUE on success, FALSE otherwise
   * @return boolean
   */
  public function test() {
    $success = FALSE;
    $this->getRunOptions();
    
    $this->options['test_started'] = date('Y-m-d H:i:s');
    
    // clear UnixBench results directory
    $this->clearResults();
    
    // temporary files used for text execution
    $ofile = sprintf('%s/%s', $this->options['output'], self::UNIX_BENCH_TEST_FILE_NAME);
    $efile = sprintf('%s/%s', $this->options['output'], self::UNIX_BENCH_TEST_ERR_FILE);
    $xfile = sprintf('%s/%s', $this->options['output'], self::UNIX_BENCH_TEST_EXIT_FILE);
    $rfile = sprintf('%s/%s', $this->options['output'], self::UNIX_BENCH_TEST_RUN_FILE);
    if (file_exists($ofile)) unlink($ofile);
    if (file_exists($efile)) unlink($efile);
    if (file_exists($xfile)) unlink($xfile);
    if (file_exists($rfile)) unlink($rfile);
    
    // open runtime file for writing (a bash script generated in this file)
    // writing this script and forking is a work around for Broken pipe errors
    // during multi-threaded Pipe-based Context Switching
    if ($fp = fopen($rfile, 'w')) {
      // create runs cript
      fwrite($fp, "#!/bin/bash\n");
      fwrite($fp, sprintf("cd %s\n", $this->options['unixbench_dir']));
      fwrite($fp, sprintf("./Run >%s 2>>%s\n", $ofile, $efile));
      fwrite($fp, sprintf("echo \$? >%s\n", $xfile));
      fclose($fp);
      exec(sprintf('chmod 755 %s', $rfile));
      print_msg(sprintf('Successfully generated runtime file %s - starting UnixBench', $rfile), isset($this->options['verbose']), __FILE__, __LINE__);
      
      // fork run script
      exec(sprintf('%s >/dev/null 2>>%s &', $rfile, $efile));
      print_msg(sprintf('UnixBench started successfully - polling for completion using exit file %s', $xfile), isset($this->options['verbose']), __FILE__, __LINE__);
      
      // wait until exit code written to $xfile
      $pos = 0;
      do {
        sleep(1);
        $buffer = trim(shell_exec(sprintf('cat %s', $ofile)));
        if (strlen($buffer) > $pos) {
          print(substr($buffer, $pos));
          $pos = strlen($buffer);
        }
        $ecode = trim(exec('ps aux | grep Run | grep perl; echo $?'))*1;
      } while(!file_exists($xfile) && $ecode === 0);
      
      // get exit code
      $ecode = trim(file_get_contents($xfile));
      $ecode = strlen($ecode) && is_numeric($ecode) ? $ecode*1 : NULL;
      
      print_msg(sprintf('UnixBench test finished with exit code %d', $ecode), isset($this->options['verbose']), __FILE__, __LINE__);
      
      // if UnixBench output included the string "aborting" - an error occurred
      if (file_exists($ofile) && strpos(file_get_contents($ofile, 'aborting'))) {
        print_msg(sprintf('UnixBench execution aborted prematurely - check output %s', $ofile), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
      }
      // if UnixBench output produced and exit code is 0 - execution was successful
      else if (file_exists($ofile) && $ecode === 0) {
        $success = TRUE;
        print_msg(sprintf('UnixBench test finished - results written to %s', $ofile), isset($this->options['verbose']), __FILE__, __LINE__);
        // get results
        $this->endTest();
      }
      // if stderr generated output - an error occurred
      else if (file_exists($efile) && filesize($efile)) {
        print_msg(sprintf('Unable run UnixBench - exit code %d', $ecode), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
        print_msg(trim(file_get_contents($efile)), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
      }
      // Some other error occurred
      else print_msg(sprintf('UnixBench failed to run - exit code %d', $ecode), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
    }
    else print_msg(sprintf('UnixBench failed to run - unable to open runtime %s file for writing', $rfile), isset($this->options['verbose']), __FILE__, __LINE__, TRUE);
    
    // remove temporary files
    if (file_exists($efile)) unlink($efile);
    if (file_exists($xfile)) unlink($xfile);
    if (file_exists($rfile)) unlink($rfile);
    
    // clear UnixBench results directory
    $this->clearResults();
    
    return $success;
  }
  
  /**
   * validate run options. returns an array populated with error messages 
   * indexed by the argument name. If options are valid, the array returned
   * will be empty
   * @return array
   */
  public function validateRunOptions() {
    $this->getRunOptions();
    $validate = array(
      'output' => array('write' => TRUE),
    );
    $validated = validate_options($this->options, $validate);
    if (!is_array($validated)) $validated = array();
    
    // look up directory hierarchy for UnixBench directory
    if (!isset($this->options['unixbench_dir'])) {
      print_msg(sprintf('UnixBench directory not set - looking up directory hierarchy'), isset($this->options['verbose']), __FILE__, __LINE__);
      $dirs = array($this->options['output']);
      if (($pwd = trim(shell_exec('pwd'))) != $this->options['output']) $dirs[] = $pwd;
      foreach($dirs as $dir) {
        while($dir != dirname($dir)) {
          if ((is_dir($udir = sprintf('%s/UnixBench', $dir)) || is_dir($udir = sprintf('%s/unixbench', $dir))) && file_exists(sprintf('%s/Run', $udir))) {
            print_msg(sprintf('UnixBench found in directory %s', $dir), isset($this->options['verbose']), __FILE__, __LINE__);
            $this->options['unixbench_dir'] = $udir;
            break;
          }
          else print_msg(sprintf('UnixBench NOT found in directory %s', $dir), isset($this->options['verbose']), __FILE__, __LINE__);
          $dir = dirname($dir);
        }
        if (isset($this->options['unixbench_dir'])) break;
      }
    }
    
    // check if UnixBench is valid and has been compiled
    if (isset($this->options['unixbench_dir']) && is_dir($this->options['unixbench_dir'])) {
      if (!file_exists($run = sprintf('%s/Run', $this->options['unixbench_dir'])) || !is_executable($run)) $validated['unixbench_dir'] = '--unixbench_dir ' . $this->options['unixbench_dir'] . ' does not contain Run or Run is not executable';
      else if (!is_dir($pgms = sprintf('%s/pgms', $this->options['unixbench_dir']))) $validated['unixbench_dir'] = 'Required directory ' . $pgms . ' does not exist';
      else if (!file_exists(sprintf('%s/arithoh', $pgms))) $validated['unixbench_dir'] = '--unixbench_dir ' . $this->options['unixbench_dir'] . ' has not been compiled - run Make all';
      else if (!is_dir($rdir = sprintf('%s/results', $this->options['unixbench_dir'])) || !is_writable($rdir)) $validated['unixbench_dir'] = '--unixbench_dir ' . $this->options['unixbench_dir'] . ' is not valid because ' . $rdir . ' is not writable';
      else print_msg(sprintf('UnixBench directory %s is valid', $this->options['unixbench_dir']), isset($this->options['verbose']), __FILE__, __LINE__);
    }
    else $validated['unixbench_dir'] = isset($this->options['unixbench_dir']) ? '--unixbench_dir ' . $this->options['unixbench_dir'] . ' is not valid' : '--unixbench_dir is required';
    
    return $validated;
  }
  
}
?>
