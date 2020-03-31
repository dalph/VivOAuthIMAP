<?php

use ZBateson\MailMimeParser\Header\Part\AddressPart;
use ZBateson\MailMimeParser\Message as MailMimeParserMessage;

class ParseMessageX
{
    protected $message = '';
    public $to = [];
    public $fromAddress = '';
    public $fromName = '';
    protected $attachments = [];
    public $attachmentNames = [];
    public $body = '';
    public $subject = '';
    public $messageId = '';
    public $datetime = '';
    const HOUR_OFFSET = 5;

    public function __construct(string $message)
    {
        $this->message = $message;

        $message = MailMimeParserMessage::from($this->message);
//        print_r($mail);

        // parse TO
        $this->to = [];
        $to = $message->getHeader('To');
        /* @var AddressPart $address */
        if ($to) {
            foreach ($to->getAddresses() as $address) {
                $this->to[$address->getEmail()] = $address->getName();
            }
        }
        $this->messageId = $message->getHeaderValue('Message-id');
        $this->subject = $message->getHeaderValue('Subject');
        $from = $message->getHeader('From');
        foreach ($from->getAddresses() as $address) {
            $this->fromAddress = $address->getEmail();
            $this->fromName = $address->getName();
        }
        $v = $message->getHeaderValue('Date');
        if ($v) {
            $this->datetime = date('Y-m-d H:i:s', strtotime('+' . self::HOUR_OFFSET . 'hour', strtotime($v)));
        }

        $this->body = $message->getHtmlContent()
            ?:
            str_replace(PHP_EOL, '<br/>', $message->getTextContent());

        $parts = $message->getAllAttachmentParts();
        $this->attachments = [];
        $this->attachmentNames = [];
        if ($parts) {
            foreach ($parts as $part) {
                $cid = $part->getContentId();
                $type = $part->getContentType();
                // если прикрепленный файл - изображение из текста письма, то преобразуем его в base64 и вставляем в тело письма
                if ($cid
                    AND $part->getContentDisposition() == 'inline'
                    AND strpos($type, 'image/') !== false
                ) {
                    $content = $part->getContent();
                    $base64 = 'data:' . $type . ';base64,' . base64_encode($content);
                    $this->body = str_replace('cid:' . $cid, $base64, $this->body);
                    continue;
                }
                $this->attachments[] = $part;
                $this->attachmentNames[] = $part->getFilename();
            }
        }
    }

    public function saveAttachments(string $attachmentDirectory, string $prefixFile = '', bool $saveToS3 = true)
    {
        if (!$this->attachments) return [];
        $res = [];
        foreach ($this->attachments as $attachment) {
            $filename = $attachment->getFilename();
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $filename4save = md5($filename) . ($ext ? '.' . $ext : '');
            $path = $attachmentDirectory;
            is_file($path) OR mkdir($path, 777, true);
            $path = $path . DIRECTORY_SEPARATOR . ($prefixFile ? $prefixFile . '_' : '') . $filename4save;
            $attachment->saveContent($path);
            $r = is_file($path);
            $res[$filename] = $r ? $path : false;
        }

        return $res;
    }
}