<?php
/**
 * @namespace
 */
namespace PEAR;

/**
 * \PEAR\LogfileAnalysis
 *
 * @category System
 * @package  PEAR_LogfileAnalysis
 * @author   Till Klampaeckel <till@php.net>
 * @license
 * @link     http://github.com/pear/PEAR_LogfileAnalysis
 */
class LogfileAnalysis
{
    /**
     * @var string $base
     */
    protected static $base;

    /**
     * pseudo __construct()
     *
     * @return void
     */    
    public static function init()
    {
        self::$base = dirname(__DIR__);
    }

    /**
     * This is for the initial cleanup. Most of the files would uncompress to
     * 'access_log' which doesn't work.
     *
     * @return void
     */
    public static function organize()
    {
        static $compressedExt = array('gz', 'bz2');

        foreach ($compressedExt as $ext) {

           $pattern = self::$bar . "/*.{$ext}";

            /**
             * @desc Create a directory for each file .gz in this directory, move it in and gunzip.
             */
            foreach (glob($pattern) as $file) {
                $base = basename($file);
                $dir  = str_replace(".{$ext}", '', $base);
                echo "{$base} / {$dir}\n";

                $target = self::$base . "/{$dir}";

                if (file_exists($target) && is_dir($target)) {
                    continue;
                }

                mkdir($target);
                rename($file, "{$target}/access_log.{$ext}");
            }
        }
    }

    /**
     * Recursive glob() function - find all files.
     *
     * @param string $pattern A glob() pattern.
     *
     * @return array
     */
    public static function globr($pattern)
    {
        static $ignore;

        if ($ignore === null) {
            $ignore = array(
                'config.ini',
                'config.ini-dist',
                basename(__FILE__),
                'process.php',
                'README.md',
                '.git',
                '.gitignore',
                '..',
                '.',
            );
        }

        $files = array();
        foreach (glob($pattern) as $file) {
            $base = basename($file);
            if ($file == __DIR__) {
                continue;
            }
            if (in_array($base, $ignore)) {
                continue;
            }
            if (!is_dir($file)) {
                $files[] = $file;
                continue;
            }
            $files = array_merge($files, self::globr("$file/*"));
        }
        return $files;
    }

    /**
     * Parse a single line from the access_log. We'll make sure the $paths are matched
     * so we avoid other stats.
     *
     * @param string $line
     *
     * @return mixed boolean if $line was empty, otherwise an array.
     */
    public static function parseLine($line)
    {
        if (empty($line)) {
            return false;
        }
        static $pattern = '/^([^ ]+) ([^ ]+) ([^ ]+) (\[[^\]]+\]) "(.*) (.*) (.*)" ([0-9\-]+) ([0-9\-]+) "(.*)" "(.*)"$/';

        $isRelevant = false;
        if (strstr($line, ' /rest')) {
            $isRelevant = true;
        }
        if (strstr($line, ' /channel.xml')) {
            $isRelevant = true;
        }
        if (strstr($line, ' /get')) {
            $isRelevant = true;
        }
        if ($isRelevant === false) {
            return false;
        }

        if (preg_match($pattern, $line, $matches)) {

            /**
             * @desc Put each part of the match in an appropriately-named variable
             * @link http://docstore.mik.ua/orelly/webprog/pcook/ch11_14.htm 
             */
            list($whole_match,$remote_host,$logname,$user,$time,
                $method,$request,$protocol,$status,$bytes,$referer,
                $user_agent) = $matches;

            /**
             * @desc Relevant user-agent follows 'PEAR/foo/PHP/bar' pattern.
             */
            if (!strstr($user_agent, '/PHP/')) {
                return false;
            }

            $resp = array(
                'line'     => $whole_match,
                'host'     => $remote_host,
                // 'log'      => $logname,
                // 'user'     => $user,
                'time'     => $time,
                'method'   => $method,
                'request'  => $request,
                // 'protocol' => $protocol,
                'status'   => $status,
                // 'bytes'    => $bytes,
                // 'referer'  => $referer,
                // 'agent'    => $user_agent,
            );

            $resp['time'] = self::parseTime($resp['time']);

            list($foo, $pear, $bar, $php) = explode('/', $user_agent);

            $resp['php']  = $php;
            $resp['pear'] = $pear;

            return $resp;
        }

        // no match
        return false;
    }

    /**
     * Parse the weird date string from the access_log into something usable we can
     * work with!
     *
     * @param string $timeStr E.g. 1/Mar/2009 23:59:20 +0000
     *
     * @return array An associative array with month, day, year, hour, min
     */
    protected static function parseTime($timeStr)
    {
        $timeStr = substr($timeStr, 1, -1);

        static $format  = '%e/%b/%Y:%H:%M:%S %z';

        $date = strptime($timeStr, $format);

        $parsed = array(
            'month' => ($date['tm_mon']+1),
            'day'   => $date['tm_mday'],
            'year'  => (1900+$date['tm_year']),
            'hour'  => $date['tm_hour'],
            'min'   => $date['tm_min'],
        );

        unset($date);
        unset($timeStr);

        return $parsed;
    }

    /**
     * Bulk docs request.
     *
     * @param stdClass $bulkDocs An object with property 'docs'. 'docs' is an array stacked.
     * @param string   $file     For debug out, the filename currently being crunched.
     * @param int      $count    The current line of said filename.
     *
     * @return void
     */
    public function sendBulkRequest(\stdClass $bulkDocs, $file, $count)
    {
        static $config;
        if ($config === null) {
            $config = parse_ini_file(self::$base . '/config.ini', true);
            if ($config === false) {
                echo "Couldn't read config.ini.";
                exit(1);
            }
        }
        static $req;
        if ($req === null) {
            require_once 'HTTP/Request2.php';

            $req = new \HTTP_Request2;
            $req->setAuth($config['couchdb']['user'], $config['couchdb']['pass']);
            $req->setMethod(\HTTP_Request2::METHOD_POST);
        }

        try {
            $req->setUrl($config['couchdb']['host'] . "/_bulk_docs");
            $req->setBody(json_encode($bulkDocs));

            $resp = $req->send();

            echo "\tBulk document request (file: {$file}, line: {$count}), ";
            echo "Response: " . $resp->getStatus();

            if ($resp->getStatus() != 201) {
                echo " [error] - " . json_decode($resp->getBody());
                echo "\n\n\n";
            }

            echo "\n";

            unset($resp);
            unset($bulkDocs);
            unset($data);
            unset($id);

        } catch (Exception $e) {
            echo "Error: " . $e->getMessage();
            exit(1);
        }
    }

    /**
     * Send data to CouchDB. This function dies on HTTP error and also if no
     * config.ini is found.
     *
     * @param array  $data  The object/document.
     * @param string $file  The filename of the log currently being crunched.
     * @param int    $count Which line are we at?
     *
     * @return void
     * @uses   \HTTP_Request2
     */
    public static function sendToCouchDb(array $data, $file, $count)
    {
        static $config;
        if ($config === null) {
            $config = parse_ini_file(self::$base . '/config.ini', true);
            if ($config === false) {
                echo "Couldn't read config.ini.";
                exit(1);
            }
        }

        static $req;
        if ($req === null) {
            require_once 'HTTP/Request2.php';

            $req = new \HTTP_Request2;
            $req->setAuth($config['couchdb']['user'], $config['couchdb']['pass']);
            $req->setMethod(\HTTP_Request2::METHOD_PUT);
        }

        $obj = (object) $data;

        $id = md5($obj->line); // generate _id

        try {
            $req->setUrl($config['couchdb']['host'] . "/{$id}");
            $req->setBody(json_encode($obj));

            $resp = $req->send();

            echo "\tDocument: {$id} (file: {$file}, line: {$count}), ";
            echo "Response: " . $resp->getStatus();

            if ($resp->getStatus() == 409) {
                echo " [duplicate]";
            }

            echo "\n";

            unset($resp);
            unset($obj);
            unset($data);
            unset($id);

        } catch (Exception $e) {
            echo "Error: " . $e->getMessage();
            exit(1);
        }
    }
}