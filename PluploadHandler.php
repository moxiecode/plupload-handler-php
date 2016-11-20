<?php


define('PLUPLOAD_MOVE_ERR', 103);
define('PLUPLOAD_INPUT_ERR', 101);
define('PLUPLOAD_OUTPUT_ERR', 102);
define('PLUPLOAD_TMPDIR_ERR', 100);
define('PLUPLOAD_TYPE_ERR', 104);
define('PLUPLOAD_UNKNOWN_ERR', 111);
define('PLUPLOAD_SECURITY_ERR', 105);

define('DS', DIRECTORY_SEPARATOR);


class PluploadHandler
{
    private $conf;
    protected $error = null;

    function __construct($conf = array())
    {
        $this->conf = array_merge(
            array(
                'file_data_name' => 'file',
                'tmp_dir' => ini_get("upload_tmp_dir") . DS . "plupload",
                'target_dir' => false,
                'cleanup' => true,
                'max_file_age' => 5 * 3600,
                'max_execution_time' => 5 * 60, // in seconds (5 minutes by default)
                'chunk' => isset($_REQUEST['chunk']) ? intval($_REQUEST['chunk']) : 0,
                'chunks' => isset($_REQUEST['chunks']) ? intval($_REQUEST['chunks']) : 0,
                'append_chunks_to_target' => true,
                'combine_chunks_on_complete' => true,
                'file_name' => isset($_REQUEST['name']) ? $_REQUEST['name'] : false,
                'allow_extensions' => false,
                'delay' => 0,
                'cb_sanitize_file_name' => array($this, 'sanitize_file_name'),
                'cb_check_file' => false,
                'cb_filesize' => array($this, 'filesize'),
                'error_strings' => array(
                    PLUPLOAD_MOVE_ERR => "Failed to move uploaded file.",
                    PLUPLOAD_INPUT_ERR => "Failed to open input stream.",
                    PLUPLOAD_OUTPUT_ERR => "Failed to open output stream.",
                    PLUPLOAD_TMPDIR_ERR => "Failed to open temp directory.",
                    PLUPLOAD_TYPE_ERR => "File type not allowed.",
                    PLUPLOAD_UNKNOWN_ERR => "Failed due to unknown error.",
                    PLUPLOAD_SECURITY_ERR => "File didn't pass security check."
                )
            ),
            $conf
        );
    }


    function handle_upload()
    {
        $conf = $this->conf;
        $this->error = null; // start fresh

        @set_time_limit($conf['max_execution_time']);

        try {
            if (!$conf['file_name']) {
                if (!empty($_FILES)) {
                    $conf['file_name'] = $_FILES[$conf['file_data_name']]['name'];
                } else {
                    throw new Exception('', PLUPLOAD_INPUT_ERR);
                }
            }

            // Cleanup outdated temp files and folders
            if ($conf['cleanup']) {
                $this->cleanup();
            }

            // Fake network congestion
            if ($conf['delay']) {
                usleep($conf['delay']);
            }

            if (is_callable($conf['cb_sanitize_file_name'])) {
                $file_name = call_user_func($conf['cb_sanitize_file_name'], $conf['file_name']);
            } else {
                $file_name = $conf['file_name'];
            }

            // Check if file type is allowed
            if ($conf['allow_extensions']) {
                if (is_string($conf['allow_extensions'])) {
                    $conf['allow_extensions'] = preg_split('{\s*,\s*}', $conf['allow_extensions']);
                }

                if (!in_array(strtolower(pathinfo($file_name, PATHINFO_EXTENSION)), $conf['allow_extensions'])) {
                    throw new Exception('', PLUPLOAD_TYPE_ERR);
                }
            }

            // Write file or chunk to appropriate temp location
            if ($conf['chunks']) {
                return $this->handle_chunk($conf['chunk'], $file_name);
            } else {
                return $this->handle_file($file_name);
            }
        } catch (Exception $ex) {
            $this->error = $ex->getCode();
            return false;
        }
    }


    /**
     * Retrieve the error code
     *
     * @return int Error code
     */
    function get_error_code()
    {
        if (!$this->error) {
            return null;
        }

        if (!isset($this->conf['error_strings'][$this->error])) {
            return PLUPLOAD_UNKNOWN_ERR;
        }

        return $this->error;
    }


    /**
     * Retrieve the error message
     *
     * @return string Error message
     */
    function get_error_message()
    {
        if ($code = $this->get_error_code()) {
            return $this->conf['error_strings'][$code];
        }
        return '';
    }


    /**
     * Combine chunks for specified file name.
     *
     * @throws Exception In case of error generates exception with the corresponding code
     *
     * @param string $file_name
     * @return string Path to the target file
     */
    function combine_chunks_for($file_name)
    {
        $file_path = $this->get_target_path_for($file_name);
        $tmp_path = $this->write_chunks_to_file("$file_path.dir.part", "$file_path.part");
        return $this->rename($tmp_path, $file_path);
    }


    protected function handle_chunk($chunk, $file_name)
    {
        $file_path = $this->get_target_path_for($file_name);

        if ($this->conf['append_chunks_to_target']) {
            $chunk_path = $this->write_upload_to("$file_path.part", false, 'ab');

            if ($this->is_last_chunk($file_name)) {
                return $this->rename($chunk_path, $file_path);
            }
        } else {
            $chunk_path = $this->write_upload_to("$file_path.dir.part" . DS . "$chunk.part");

            // Check if all chunks already uploaded
            if ($this->is_last_chunk($file_name) && $this->conf['combine_chunks_on_complete']) {
                return $this->combine_chunks_for($file_name);
            }
        }

        return array(
            'name' => $file_name,
            'path' => $chunk_path,
            'chunk' => $chunk,
            'size' => call_user_func($this->conf['cb_filesize'], $chunk_path)
        );
    }


    protected function handle_file($file_name)
    {
        $file_path = $this->get_target_path_for($file_name);
        $tmp_path = $this->write_upload_to($file_path . ".part");
        return $this->rename($tmp_path, $file_path);
    }


    protected function rename($tmp_path, $file_path)
    {
        // Upload complete write a temp file to the final destination
        if (!$this->file_is_ok($tmp_path)) {
            if ($this->conf['cleanup']) {
                @unlink($tmp_path);
            }
            throw new Exception('', PLUPLOAD_SECURITY_ERR);
        }

        if (rename($tmp_path, $file_path)) {
            return array(
                'name' => basename($file_path),
                'path' => $file_path,
                'size' => call_user_func($this->conf['cb_filesize'], $file_path)
            );
        } else {
            return false;
        }
    }


    /**
     * Writes either a multipart/form-data message or a binary stream
     * to the specified file.
     *
     * @throws Exception In case of error generates exception with the corresponding code
     *
     * @param string $file_path The path to write the file to
     * @param string [$file_data_name='file'] The name of the multipart field
     * @return string Path to the target file
     */
    protected function write_upload_to($file_path, $file_data_name = false, $mode = 'wb')
    {
        if (!$file_data_name) {
            $file_data_name = $this->conf['file_data_name'];
        }

        $base_dir = dirname($file_path);
        if (!file_exists($base_dir) && !@mkdir($base_dir, 0777, true)) {
            throw new Exception('', PLUPLOAD_TMPDIR_ERR);
        }

        if (!empty($_FILES)) {
            if (!isset($_FILES[$file_data_name]) || $_FILES[$file_data_name]["error"] || !is_uploaded_file($_FILES[$file_data_name]["tmp_name"])) {
                throw new Exception('', PLUPLOAD_INPUT_ERR);
            }
            return $this->write_to_file($_FILES[$file_data_name]["tmp_name"], $file_path, $mode);
        } else {
            return $this->write_to_file("php://input", $file_path, $mode);
        }
    }


    protected function write_to_file($source_path, $target_path, $mode = 'wb')
    {
        if (!$out = @fopen($target_path, $mode)) {
            throw new Exception('', PLUPLOAD_OUTPUT_ERR);
        }

        if (!$in = @fopen($source_path, "rb")) {
            die('hey');
            throw new Exception('', PLUPLOAD_INPUT_ERR);
        }

        while ($buff = fread($in, 4096)) {
            fwrite($out, $buff);
        }

        @fclose($out);
        @fclose($in);

        return $target_path;
    }


    /**
     * Combine chunks from the specified folder into the single file.
     *
     * @throws Exception In case of error generates exception with the corresponding code
     *
     * @param string $chunk_dir Temp directory with the chunks
     * @param string $file_path The file to write the chunks to
     * @return string File path containing combined chunks
     */
    protected function write_chunks_to_file($chunk_dir, $file_path)
    {
        if (!$out = @fopen($file_path, "wb")) {
            throw new Exception('', PLUPLOAD_OUTPUT_ERR);
        }

        for ($i = 0; $i < $this->conf['chunks']; $i++) {
            $chunk_path = $chunk_dir . DS . "$i.part";
            if (!file_exists($chunk_path)) {
                throw new Exception('', PLUPLOAD_MOVE_ERR);
            }

            if (!$in = @fopen($chunk_path, "rb")) {
                throw new Exception('', PLUPLOAD_INPUT_ERR);
            }

            while ($buff = fread($in, 4096)) {
                fwrite($out, $buff);
            }
            @fclose($in);
        }
        @fclose($out);

        // Cleanup
        if ($this->conf['cleanup']) {
            $this->rrmdir($chunk_dir);
        }
        return $file_path;
    }


    protected function is_last_chunk($file_name)
    {
        if ($this->conf['append_chunks_to_target']) {
            return $this->conf['chunks'] && $this->conf['chunks'] == $this->conf['chunk'] + 1;
        } else {
            $file_path = $this->get_target_path_for($file_name);
            $chunks = glob("$file_path.dir.part/*.part");
            return sizeof($chunks) == $this->conf['chunks'];
        }
    }


    protected function file_is_ok($path)
    {
        return !is_callable($this->conf['cb_check_file']) || call_user_func($this->conf['cb_check_file'], $path);
    }


    function get_filesize_for($file_name)
    {
        return call_user_func($this->conf['cb_filesize'], get_target_path_for($file_name));
    }


    function get_target_path_for($file_name)
    {
        return rtrim($this->conf['target_dir'], DS) . DS . $file_name;
    }


    function send_nocache_headers()
    {
        // Make sure this file is not cached (as it might happen on iOS devices, for example)
        header("Expires: Mon, 26 Jul 1997 05:00:00 GMT");
        header("Last-Modified: " . gmdate("D, d M Y H:i:s") . " GMT");
        header("Cache-Control: no-store, no-cache, must-revalidate");
        header("Cache-Control: post-check=0, pre-check=0", false);
        header("Pragma: no-cache");
    }


    function send_cors_headers($headers = array(), $origin = '*')
    {
        $allow_origin_present = false;

        if (!empty($headers)) {
            foreach ($headers as $header => $value) {
                if (strtolower($header) == 'access-control-allow-origin') {
                    $allow_origin_present = true;
                }
                header("$header: $value");
            }
        }

        if ($origin && !$allow_origin_present) {
            header("Access-Control-Allow-Origin: $origin");
        }

        // other CORS headers if any...
        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            exit; // finish preflight CORS requests here
        }
    }


    protected function cleanup()
    {
        // Remove old temp files
        if (file_exists($this->conf['target_dir'])) {
            foreach (glob($this->conf['target_dir'] . '/*.part') as $tmpFile) {
                if (time() - filemtime($tmpFile) < $this->conf['max_file_age']) {
                    continue;
                }
                if (is_dir($tmpFile)) {
                    self::rrmdir($tmpFile);
                } else {
                    @unlink($tmpFile);
                }
            }
        }
    }


    /**
     * Sanitizes a filename replacing whitespace with dashes
     *
     * Removes special characters that are illegal in filenames on certain
     * operating systems and special characters requiring special escaping
     * to manipulate at the command line. Replaces spaces and consecutive
     * dashes with a single dash. Trim period, dash and underscore from beginning
     * and end of filename.
     *
     * @author WordPress
     *
     * @param string $filename The filename to be sanitized
     * @return string The sanitized filename
     */
    protected function sanitize_file_name($filename)
    {
        $special_chars = array("?", "[", "]", "/", "\\", "=", "<", ">", ":", ";", ",", "'", "\"", "&", "$", "#", "*", "(", ")", "|", "~", "`", "!", "{", "}");
        $filename = str_replace($special_chars, '', $filename);
        $filename = preg_replace('/[\s-]+/', '-', $filename);
        $filename = trim($filename, '.-_');
        return $filename;
    }


    /**
     * Concise way to recursively remove a directory
     * http://www.php.net/manual/en/function.rmdir.php#108113
     *
     * @param string $dir Directory to remove
     */
    protected function rrmdir($dir)
    {
        foreach (glob($dir . '/*') as $file) {
            if (is_dir($file))
                $this->rrmdir($file);
            else
                unlink($file);
        }
        rmdir($dir);
    }


    /**
     * PHPs filesize() fails to measure files larger than 2gb
     * http://stackoverflow.com/a/5502328/189673
     *
     * @param string $file Path to the file to measure
     * @return int
     */
    protected function filesize($file)
    {
        static $iswin;
        if (!isset($iswin)) {
            $iswin = (strtoupper(substr(PHP_OS, 0, 3)) == 'WIN');
        }

        static $exec_works;
        if (!isset($exec_works)) {
            $exec_works = (function_exists('exec') && !ini_get('safe_mode') && @exec('echo EXEC') == 'EXEC');
        }

        // try a shell command
        if ($exec_works) {
            $cmd = ($iswin) ? "for %F in (\"$file\") do @echo %~zF" : "stat -c%s \"$file\"";
            @exec($cmd, $output);
            if (is_array($output) && ctype_digit($size = trim(implode("\n", $output)))) {
                return $size;
            }
        }

        // try the Windows COM interface
        if ($iswin && class_exists("COM")) {
            try {
                $fsobj = new COM('Scripting.FileSystemObject');
                $f = $fsobj->GetFile(realpath($file));
                $size = $f->Size;
            } catch (Exception $e) {
                $size = null;
            }
            if (ctype_digit($size)) {
                return $size;
            }
        }

        // if all else fails
        return filesize($file);
    }
}

