<?php

namespace App\Services\Suppliers;

use RuntimeException;

class VerwimpFtpsClient
{
    public function download(array $config): string
    {
        $host = trim((string) ($config['host'] ?? ''));
        $username = (string) ($config['username'] ?? '');
        $password = (string) ($config['password'] ?? '');
        $port = (int) ($config['port'] ?? 21);
        $root = trim((string) ($config['root'] ?? ''), '/');
        $file = trim((string) ($config['file'] ?? ''), '/');

        if ($host === '') {
            throw new RuntimeException('Verwimp FTPS host is not configured.');
        }

        if ($username === '') {
            throw new RuntimeException('Verwimp FTPS username is not configured.');
        }

        if ($password === '') {
            throw new RuntimeException('Verwimp FTPS password is not configured.');
        }

        if ($file === '') {
            throw new RuntimeException('Verwimp FTPS file is not configured.');
        }

        $path = $root !== ''
            ? $root.'/'.$file
            : $file;

        $url = 'ftp://'.$host.'/'.$path;

        $curl = curl_init();

        if ($curl === false) {
            throw new RuntimeException('Could not initialize cURL for Verwimp FTPS.');
        }

        $options = [
            CURLOPT_URL => $url,
            CURLOPT_PORT => $port,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => $username.':'.$password,

            // Zelfde idee als: curl --ssl-reqd
            CURLOPT_USE_SSL => CURLUSESSL_ALL,

            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,

            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 30,
        ];

        // Zelfde idee als: curl --ftp-method nocwd
        if (defined('CURLFTPMETHOD_NOCWD')) {
            $options[CURLOPT_FTP_FILEMETHOD] = CURLFTPMETHOD_NOCWD;
        }

        // Alleen nodig als EPSV/PASV moeilijk doet.
        // Voor nu configureerbaar houden.
        if ((bool) ($config['disable_epsv'] ?? false)) {
            $options[CURLOPT_FTP_USE_EPSV] = false;
        }

        curl_setopt_array($curl, $options);

        $contents = curl_exec($curl);

        $errorNumber = curl_errno($curl);
        $errorMessage = curl_error($curl);
        $info = curl_getinfo($curl);

        curl_close($curl);

        if ($contents === false) {
            throw new RuntimeException(sprintf(
                'Could not download Verwimp FTPS file [%s]. cURL error %s: %s',
                $url,
                $errorNumber,
                $errorMessage
            ));
        }

        if (! is_string($contents) || trim($contents) === '') {
            throw new RuntimeException(sprintf(
                'Downloaded Verwimp FTPS file [%s] is empty. HTTP/FTP code: %s',
                $url,
                $info['http_code'] ?? 'unknown'
            ));
        }

        return $contents;
    }
}
