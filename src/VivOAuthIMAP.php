<?php

use ParseMessageX;

/**
 * Description of VIVOAuthIMAP
 * This is a Library to Support OAuth in IMAP
 * This is designed to work with Gmail
 * @author Vivek
 * Fork:
 * @author Anton Baranov helpmedalph@gmail.com
 */
class VivOAuthIMAP
{

    /**
     * @var string $host
     */
    public $host;

    /**
     * @var integer $port
     */
    public $port;

    /**
     * @var string $username
     */
    public $username;

    /**
     * @var string $password
     */
    public $password;

    /**
     * @var string $accessToken
     */
    public $accessToken;

    /**
     * @var FilePointer $sock
     */
    protected $fp;

    /**
     * Command Counter
     * @var string
     */
    protected $codeCounter = 1;

    /**
     * If successfull login then set to true else false
     * @var boolean
     */
    protected $isLoggedIn = false;

    protected $debug = false;
    protected $_isConnectedToServer = false;
    const USE_UID = true;

    /**
     * @return bool
     */
    public function isConnectedToServer(): bool
    {
        return $this->_isConnectedToServer;
    }

    /**
     * @param bool $debug
     */
    public function setDebug(bool $debug)
    {
        $this->debug = $debug;
        $this->_log('<pre>');
    }

    /**
     * Connects to Host if successful returns true else false
     * @return bool
     */
    protected function _connect(): bool
    {
        $this->_log('connect: ' . $this->host . ':' . $this->port);
        $this->fp = fsockopen($this->host, $this->port, $errno, $errstr, 30);
        return $this->_isConnectedToServer = !!$this->fp;
    }

    /**
     * Closes the file pointer
     */
    protected function _disconnect()
    {
        fclose($this->fp);
        $this->_log('disconnect');
    }

    /**
     * Login with username password / access_token returns true if successful else false
     * @return boolean
     */
    public function login()
    {

        if ($this->_connect()) {
            $command = NULL;
            if (isset($this->username) && isset($this->password)) {
                $command = "LOGIN $this->username $this->password";
            } else if (isset($this->username) && isset($this->accessToken)) {
                $str = "user=$this->username\1auth=Bearer $this->accessToken\1\1";
//                var_dump($str);
                $token = base64_encode($str);
//                var_dump($token);
                $command = "AUTHENTICATE XOAUTH2 $token";
            }

            if ($command != NULL) {
                $this->_writeCommand("A" . $this->codeCounter, $command);
                $response = $this->_readResponse("A" . $this->codeCounter);

                if ($response[0][0] == "OK") { //Got Successful response
                    $this->isLoggedIn = true;
                    $this->selectInbox();
                    return true;
                }
            }

            return false;
        }
        return false;
    }

    /**
     * Logout then disconnects
     */
    public function logout()
    {
        $this->_writeCommand("A" . $this->codeCounter, "LOGOUT");
        $this->_readResponse("A" . $this->codeCounter);
        $this->_disconnect();
        $this->isLoggedIn = false;
    }

    /**
     * Returns true if user is authenticated else false
     * @return boolean
     */
    public function isAuthenticated()
    {
        return $this->isLoggedIn;
    }

    /**
     * Fetch a single mail header and return
     * @param integer $id
     * @return array
     */
    public function getHeader($id)
    {
        $this->_writeCommand("A" . $this->codeCounter, "FETCH $id RFC822.HEADER");
        $response = $this->_readResponse("A" . $this->codeCounter);

        if ($response[0][0] == "OK") {
            $modifiedResponse = $response;
            unset($modifiedResponse[0]);
            return $modifiedResponse;
        }

        return $response;
    }

    /**
     * Returns headers array
     * @param integer $from
     * @param integer $to
     * @return array
     */
    public function getHeaders($from, $to)
    {
        $response = $this->_writeAndRead("FETCH $from:$to RFC822.HEADER", true);
        return $this->_modifyResponse($response);
    }

    /**
     * Fetch a single mail and return
     * @param integer $id
     * @return array
     */
    public function getMessage($id)
    {
        $command = "FETCH $id RFC822";
        if (self::USE_UID) $command = "UID " . $command;
        return $this->_writeAndRead($command, true);
    }

    /**
     * Returns mails array
     * @param integer $from
     * @param integer $to
     * @return array
     */
    public function getMessages($from, $to)
    {
        $command = "FETCH $from:$to RFC822";
        if (self::USE_UID) $command = "UID " . $command;
        return $this->_writeAndRead($command, true);
    }

    /**
     * Search in FROM ie. Email Address
     * @param string $email
     * @return array
     */
    public function searchFrom($email)
    {
        $this->_writeCommand("A" . $this->codeCounter, "SEARCH FROM \"$email\"");
        $response = $this->_readResponse("A" . $this->codeCounter);
        //Fetch by ids got in response
        $ids = explode(" ", trim($response[0][1]));
        unset($ids[0]);
        unset($ids[1]);
        $ids = array_values($ids);
        $stringIds = implode(",", $ids);
        $mails = $this->getMessage($stringIds);
        return $mails;
    }

    /**
     * Selects inbox for further operations
     */
    protected function selectInbox()
    {
        $this->_writeCommand("A" . $this->codeCounter, "EXAMINE INBOX");
        $this->_readResponse("A" . $this->codeCounter);
    }

    /**
     * List all folders
     * @return array
     */
    public function listFolders()
    {
        $response = $this->_writeAndRead("LIST \"\" \"*\"");
        $line = $response[0][1];
        $statusString = explode("*", $line);

        $totalStrings = count($statusString);

        $finalFolders = array();

        for ($i = 1; $i < $totalStrings; $i++) {
            $status = trim($statusString[$i]);
            if (!$status) continue;

            $status = explode("\"|\" ", $status);

            if (!strpos($status[0], "\Noselect")) {
                $folder = str_replace("\"", "", $status[1]);
                array_push($finalFolders, $folder);
            }
        }

        return $finalFolders;
    }

    /**
     * Examines the folder
     * @param string $folder
     * @return boolean
     */
    public function selectFolder(string $folder): bool
    {
        $this->_log('select folder = ' . $folder);
        $response = $this->_writeAndRead("EXAMINE \"$folder\"");
        return ($response[0][0] == "OK");
    }

    public function totalMails($folder = "INBOX")
    {
        $response = $this->_writeAndRead("STATUS \"$folder\" (MESSAGES)");

        $line = $response[0][1];
        $splitMessage = explode("(", $line);
        $splitMessage[1] = str_replace("MESSAGES ", "", $splitMessage[1]);
        $count = str_replace(")", "", $splitMessage[1]);

        return $count;
    }

    /**
     * The APPEND command appends the literal argument as a new message
     *  to the end of the specified destination mailbox
     *
     * @param string $mailbox MANDATORY
     * @param string $message MANDATORY
     * @param string $flags OPTIONAL DEFAULT "(\Seen)"
     * @param string $from OPTIONAL
     * @param string $to OPTIONAL
     * @param string $subject OPTIONAL
     * @param string $messageId OPTIONAL DEFAULT uniqid()
     * @param string $mimeVersion OPTIONAL DEFAULT "1.0"
     * @param string $contentType OPTIONAL DEFAULT "TEXT/PLAIN;CHARSET=UTF-8"
     *
     * @return bool false if mandatory params are not set or empty or if command execution fails, otherwise true
     */
    public function appendMessage($mailbox, $message, $from = "", $to = "", $subject = "", $messageId = "", $mimeVersion = "", $contentType = "", $flags = "(\Seen)")
    {
        if (!isset($mailbox) || !strlen($mailbox)) return false;
        if (!isset($message) || !strlen($message)) return false;
        if (!strlen($flags)) return false;

        $date = date('d-M-Y H:i:s O');
        $crlf = "\r\n";

        if (strlen($from)) $from = "From: $from";
        if (strlen($to)) $to = "To: $to";
        if (strlen($subject)) $subject = "Subject: $subject";
        $messageId = (strlen($messageId)) ? "Message-Id: $messageId" : "Message-Id: " . uniqid();
        $mimeVersion = (strlen($mimeVersion)) ? "MIME-Version: $mimeVersion" : "MIME-Version: 1.0";
        $contentType = (strlen($contentType)) ? "Content-Type: $contentType" : "Content-Type: TEXT/PLAIN;CHARSET=UTF-8";

        $composedMessage = $date . $crlf;
        if (strlen($from)) $composedMessage .= $from . $crlf;
        if (strlen($subject)) $composedMessage .= $subject . $crlf;
        if (strlen($to)) $composedMessage .= $to . $crlf;
        $composedMessage .= $messageId . $crlf;
        $composedMessage .= $mimeVersion . $crlf;
        $composedMessage .= $contentType . $crlf . $crlf;
        $composedMessage .= $message . $crlf;

        $size = strlen($composedMessage);

        $command = "APPEND \"$mailbox\" $flags {" . $size . "}" . $crlf . $composedMessage;

        $this->_writeCommand("A" . $this->codeCounter, $command);
        $response = $this->_readResponse("A" . $this->codeCounter);

        if ($response[0][0] == "OK") return true;

        return false;
    }

    /**
     * Write's to file pointer
     * @param string $code
     * @param string $command
     */
    protected function _writeCommand($code, $command)
    {
        $this->_log("[$code] $command");
        fwrite($this->fp, $code . " " . $command . "\r\n");
    }

    /**
     * Reads response from file pointer, parse it and returns response array
     * @param string $code
     * @return array
     */
    protected function _readResponse($code)
    {
        $response = array();

        $i = 1;
        // $i = 1, because 0 will be status of response
        // Position 0 server reply two dimentional
        // Position 1 message

        while ($line = fgets($this->fp)) {
            $checkLine = preg_split('/\s+/', $line, 0, PREG_SPLIT_NO_EMPTY);
            if (@$checkLine[0] == $code) {
                $response[0][0] = $checkLine[1];
                break;
            } else if (@$checkLine[0] != "*") {
                if (isset($response[1][$i]))
                    $response[1][$i] = $response[1][$i] . $line;
                else
                    $response[1][$i] = $line;
            }
            if (@$checkLine[0] == "*") {
                if (isset($response[0][1]))
                    $response[0][1] = $response[0][1] . $line;
                else
                    $response[0][1] = $line;
                if (isset($response[1][$i])) {
                    $i++;
                }
            }
        }
        if ($this->debug AND $response AND is_array($response)) {
            foreach ($response as $r) {
                $this->_log($r);
            }
        }
        $this->codeCounter++;
        return $response;
    }

    /**
     * If response is OK then removes server response status messages else returns the original response
     * @param array $response
     * @return array
     */
    protected function _modifyResponse($response)
    {
        if ($response[0][0] == "OK") {
            $modifiedResponse = $response[1];
            return $modifiedResponse;
        }
        return $response;
    }

    public function listActiveFolders()
    {
        $res = $this->listFolders();
        return array_diff($res, explode(',', 'Spam,Trash,Drafts,Outbox'));
    }

    /**
     * Return all folders with income mail
     * @return array
     */
    public function listInboxFolders(): array
    {
        $res = $this->listActiveFolders();
        return array_diff($res, explode(',', 'Sent'));
    }

    /**
     * Return all folders with sent mails
     * @return array
     */
    public function listOutboxFolders(): array
    {
        return ['Sent'];
    }

    /**
     * @param int $mailId
     * @param bool $onlyHeaders
     * @return ParseMessageX|null
     * @throws Exception
     */
    public function parseMessage(int $mailId, bool $onlyHeaders = false)
    {
        if (!$this->isLoggedIn) throw new \Exception('Login first');
        if ($onlyHeaders) {
            $mails = $this->getHeaders($mailId, $mailId);
        } else {
            $mails = $this->getMessage($mailId);
        }
        if (!$mails) return null;
        $mail = reset($mails);
        return new ParseMessageX($mail);
        /*
        print_r($message->to);
        print_r($message->body);
        print_r($message->attachmentNames);

//        $res = $message->saveAttachments($attachdir, $mail_id, false);
        $res = $message->saveAttachments('mail_files/'.$mail_id,'', true);
        var_dump($res); */
    }

    /**
     * Search mails with date between $sinceDate and $beforeDate
     * @param string $sinceDate
     * @param string $beforeDate
     * @return array
     * @throws Exception
     */
    public function searchSince(string $sinceDate, string $beforeDate = '')
    {
        $date_str = date('d-M-Y', strtotime($sinceDate));
        if ($beforeDate == $sinceDate) {
            $command = 'SEARCH ON ' . $date_str;
        } else {
            $command = 'SEARCH SINCE ' . $date_str;
            if ($beforeDate) {
                // $beforeDate не включает в поиск, поэтому прибавляем 1 день
                $date_str = date('d-M-Y', strtotime('+1day', strtotime($beforeDate)));
                $command .= ' BEFORE ' . $date_str;
            }
        }
        if (static::USE_UID) {
            $command = 'UID ' . $command; // for get UID, not sequence number
        }
        $this->_log($command);
        $response = $this->_writeAndRead($command);
        //Fetch by ids got in response
        $ids = explode(" ", trim($response[0][1]));
        unset($ids[0]);
        unset($ids[1]);
        $ids = array_values($ids);
        if (static::USE_UID) {
            return $ids;
        }
        $res = [];
        foreach ($ids as $id) {
            $p = $this->parseMessage($id, true);
            $res[$id] = $p->messageId;
        }
        return $res;
    }

    public function searchByUid(string $minUid = '1'): array
    {
        $command = "UID SEARCH UID $minUid:*";
        if ($this->debug) {
            echo $command . PHP_EOL;
        }
        $response = $this->_writeAndRead($command);
        //Fetch by ids got in response
        $ids = explode(" ", trim($response[0][1]));
        unset($ids[0]);
        unset($ids[1]);
        $ids = array_values($ids);
        return $ids;
    }

    protected function _log($message)
    {
        if (!$this->debug) return;
        echo $message . PHP_EOL;
    }

    protected function _getFolderStatusParam(string $folder, string $param)
    {
        $response = $this->_writeAndRead("STATUS \"$folder\" ($param)");
        $line = trim($response[0][1]);
        $len = strlen($param);
        return trim(strstr(substr(strstr($line, $param), $len), ')', true));
    }

    public function getUidNext(string $folder)
    {
        return $this->_getFolderStatusParam($folder, 'UIDNEXT');
    }

    public function getUidValidity(string $folder)
    {
        return $this->_getFolderStatusParam($folder, 'UIDVALIDITY');
    }

    protected function _writeAndRead(string $command, bool $modify = false)
    {
        $code = "A" . $this->codeCounter;
        $this->_writeCommand($code, $command);
        $response = $this->_readResponse($code);
        if ($modify) $response = $this->_modifyResponse($response);
        return $response;
    }

}