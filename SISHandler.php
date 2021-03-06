<?php

namespace Monolog\Handler;
use Monolog\Logger;


class SISHandler extends AbstractProcessingHandler
{
    public static $levels = [
        0 => "EMERGENCY",
        1 => "ALERT",
        2 => "CRITICAL",
        3 => "ERROR",
        4 => "WARNING",
        5 => "NOTICE",
        6 => "INFORMATIONAL",
        7 => "DEBUG",
    ];

    private $apiURL;
    private $apiKey;
    private $message;

    public function __construct($apiURL, $apiKey, $level = Logger::DEBUG, $bubble = true)
    {
        $this->apiKey = $apiKey;
        $this->apiURL = $apiURL;
        parent::__construct($level, $bubble);
    }

    protected function write(array $record): void
    {
        $this->message = $record;
        $this->makeRequest($this->message);
    }

    protected function makeRequest($data)
    {

        // If IntrospectionProcessor was loaded add the file path and line number
        if (isset($data["extra"]["line"]) && isset($data["extra"]["file"]) && empty($data["context"])) {
            $data["message"] = "{$data["extra"]["file"]}:{$data["extra"]["line"]} - {$data["message"]}";
        }

        // Process data
        $dataSIS        = new \stdClass();
        $dataSIS->level = array_search($data["level_name"], self::$levels);

        // If nothing is passed on the second monolog parameter we should assume the user is
        // providing an object or something that was parsed by a formatter.
        if (empty($data["context"])) {
            $dataSIS->message     = $data["message"];
            $dataSIS->description = rtrim(strtok($data["message"], "\n"));
        } else {
            $dataSIS->message     = $data["context"];
            $dataSIS->description = $data["message"];
        }

        // If WebProcessor was loaded add the URL, IP and HTTP Method
        if (isset($data["extra"]["url"]) && isset($data["extra"]["ip"]) && isset($data["extra"]["http_method"])) {
            $context = "{$data["extra"]["ip"]} - {$data["extra"]["http_method"]} {$data["extra"]["url"]}" .PHP_EOL;
            if (is_array($dataSIS->message)) {
                $dataSIS->message = [
                    "message" => $dataSIS->message,
                    "context" => $context,
                ];
            } else {
                $dataSIS->message = $context . \PHP_EOL . $dataSIS->message;
            }
        }

        if (strlen($dataSIS->description) > 255) {
            $dataSIS->description = substr($dataSIS->description, 0,252) . "...";
        }

        if (isset($dataSIS->message["message"]["trace"])) {
            $trace = $dataSIS->message["message"]["trace"];
            unset($dataSIS->message["message"]["trace"]);
            $dataSIS->message = $trace . PHP_EOL . PHP_EOL . json_encode($dataSIS->message, JSON_PRETTY_PRINT);
        };

        $dataSIS = json_encode($dataSIS);

        // Prepare headers
        $headers = [
            "Content-Type"   => "application/json",
            "Content-Length" => strlen($dataSIS),
            "Authorization"  => $this->apiKey
        ];

        foreach ($headers as $header => &$content)
            $content = "{$header}: {$content}";

        $headers = array_values($headers);

        // Send the request
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $this->apiURL);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $dataSIS);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_exec($curl);
        curl_close($curl);
    }
}
