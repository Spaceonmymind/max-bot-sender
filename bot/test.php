<?php

function logMessage(string $msg): void {
    $date = date('d.m.Y');
    $logFile = $date . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] $msg\n";
    file_put_contents($logFile, $line, FILE_APPEND);
    echo $line;
}

logMessage('test test');