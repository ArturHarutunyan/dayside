<?php 

namespace GitWebCommit;

class Git {
    
    public $dir;
    public $error = false;
    public $content = false;
    
    function __construct($path) {        
        $this->dir = $path;
    }
    
    function run_command($array_args) {
        
        foreach($array_args as $v)
            $args .= ' '. escapeshellarg($v);

        $command = escapeshellcmd($command);
        
        $descriptorspec = array(
            0 => Array ('pipe', 'r'),  // stdin
            1 => Array ('pipe', 'w'),  // stdout
            2 => Array ('pipe', 'w'),  // stderr
        );

        $pipes = Array ();
        
        $process = proc_open('git '.$args, $descriptorspec, $pipes, $this->dir);

        if (is_resource($process)) {
            stream_set_blocking($pipes[0], 1);
            stream_set_blocking($pipes[1], 1);
            stream_set_blocking($pipes[2], 1);
        }
        
        $this->content = stream_get_contents($pipes[1]);        
        $this->error = stream_get_contents($pipes[2]);
        
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        
        return $this->content;
    }
    
    function stage_all() {
        return $this->run_command(array("add", "-A"));
    }
    
    function unstage($file) {
        return $this->run_command(array('reset', "HEAD", "--", $file));
    }
    
    function stage($file) {
        if (file_exists($this->dir."/".$file)){                
            return $this->run_command(array('add', $file));
        } else {
            return $this->run_command(array('rm', $file));
        }        
    }
    
    function checkout_file($file_status) {
        $file = $file_status['file'];
        $old_file = @$file_status['old_file'];
        
        if($old_file){
            $this->run_command(array('reset', "HEAD", "--", $old_file));
            $this->run_command(array("checkout", "--", $old_file));
        }        
        
        $this->run_command(array('reset', "HEAD", "--", $file));
        $this->run_command(array("checkout", "-f", "--", $file));
        $this->run_command(array("clean", "-f", $file));
    }
    
    function current_branch() {
        $branches = $this->run_command(array("branch"));
        $arr_branches = preg_split("/\n/", $branches, -1, PREG_SPLIT_NO_EMPTY);
        foreach($arr_branches as $branch){
            if(substr($branch,0,1) == "*"){
                $result=trim(substr($branch,2));
            }
        }
        return $result;
    }    
    
    function switch_branch($branch_name) {
        return $this->run_command(array('checkout', '--quiet', $branch_name)); 
    }
        
    function get_branches() {
        $branches = $this->run_command(array("branch"));
        $arr_branches = preg_split("/\n/", $branches, -1, PREG_SPLIT_NO_EMPTY);
        $result = array();
        foreach($arr_branches as $branch){
            $result[]=trim(substr($branch,2));
        }
        return $result;
    }
    
    function last_15_commits($name_branch=null) {
        $commits = $this->run_command(array('log', '-15', '--pretty=format:%h>%H>%s', $name_branch));
        $arr_commits = preg_split("/\n/", $commits, -1, PREG_SPLIT_NO_EMPTY);
        $result = array();        
        foreach($arr_commits as $commit){
            $arr = explode(">",$commit);
            $result[] = array("sha_short"=>$arr[0],"sha_full"=>$arr[1],"message"=>$arr[2]);
        }
        return $result;
    }
    
    function commit($message) {
        return $this->run_command(array("commit", "-m", escapeshellarg($message)));
    }
    
    function last_commit_in_branch($branch_name=null) {
        return $this->run_command(array('log', '--pretty=format:%H', '-1', $branch_name)); 
    }
    
    function history($commit_sha, $name_branch=null) {
        $status = array();
        $string = $this->run_command(array("diff", "--name-status", '--find-renames', $commit_sha."^", $commit_sha));
        if ($this->error) return $status;
        
        $lines = preg_split("/\\r\\n|\\r|\\n/", $string, -1,  PREG_SPLIT_NO_EMPTY);        
       
        foreach ($lines as $line) {
            $str = rtrim($line);
            $str = preg_split("/\s/", $str, -1,  PREG_SPLIT_NO_EMPTY); 
            $one = array(); 
            $one['state'] = $str[0]; 
            if(substr($one['state'],0,1)=="R"){                
                $one['old_file'] = $file_1 = $str[1];
                $one['file'] = $file_2 = $str[2];
            } else {
                $one['file'] = $file_1 = $file_2 = $str[1];
            }
            $one['diff_staged'] = $this->run_command(array('diff', $commit_sha."^", $commit_sha, '--', $file_1, $file_2)); 
            $one['diff_wt'] = '';

            if ($this->error) return $status;
            $status[$one['file']] = $one;
        }
        
        return $status;
    }
    
    function current_status($file=null) {
        $status = array();
        $string = $this->run_command(array("status", "--porcelain", "--untracked-files", $file));
        if ($this->error) return $status;
        
        $lines = preg_split("/\\r\\n|\\r|\\n/", $string, -1,  PREG_SPLIT_NO_EMPTY);        
        
        foreach ($lines as $line) {
            $one = array('partial'=>false);
            $str = rtrim($line);           
            
            if ($str[0]!=' ' && $str[0]!='?') {
                $one['staged'] = 1;
                if ($str[1]!=' ') $one['partial'] = true;
            } else {
                $one['staged'] = 0;
            }
            
            $one['state'] = $str[0].$str[1];            
            $one['file'] = substr($str, 3);
            
            if ($one['staged']) {
                $old_file = @$one['old_file']?:'';
                $one['diff_staged'] = $this->run_command(array('diff', '--cached', '--find-renames', '--', $one['file'],$old_file));
            } else {
                $one['diff_staged'] = "";
            }
            if (!$one['staged'] || $one['partial']) {
                if ($str[0]=='?') {
                    $one['diff_wt'] = $this->run_command(array('diff', '--', '/dev/null', $one['file']));
                } else {
                    $one['diff_wt'] = $this->run_command(array('diff', 'HEAD', '--', $one['file']));
                }
            } else {
                $one['diff_wt'] = "";
            }
            $status[$one['file']] = $one;
        }        
        return $status;
    }
}