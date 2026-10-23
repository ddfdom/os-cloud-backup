<?php

/*
 * Copyright (c) 2026 ddfdom
 * SPDX-License-Identifier: MIT, see LICENSE
 */

namespace OPNsense\Backup;

/**
 * Backup to an S3 compatible bucket (path style requests, AWS signature v4)
 * @package OPNsense\Backup
 */
class S3 extends RemoteStorage implements IBackupProvider
{
    protected $modelClass = 'OPNsense\Backup\S3Settings';

    public function getName()
    {
        return gettext('S3');
    }

    protected function providerFields()
    {
        return [
            [
                'name' => 'endpoint',
                'type' => 'text',
                'label' => gettext('Endpoint URL'),
                'help' => gettext('Without trailing slash, for example https://s3.eu-west-3.amazonaws.com or https://minio.example.com:9000'),
            ],
            [
                'name' => 'region',
                'type' => 'text',
                'label' => gettext('Region'),
                'help' => gettext('For example eu-west-3, most S3 compatible services accept us-east-1'),
            ],
            ['name' => 'bucket', 'type' => 'text', 'label' => gettext('Bucket')],
            ['name' => 'access_key', 'type' => 'text', 'label' => gettext('Access key')],
            ['name' => 'secret_key', 'type' => 'password', 'label' => gettext('Secret key')],
            [
                'name' => 'path',
                'type' => 'text',
                'label' => gettext('Directory (optional)'),
                'help' => gettext('Key prefix without leading or trailing slash, for example opnsense/fw1'),
            ],
        ];
    }

    protected function put($name, $data)
    {
        $this->request('PUT', $this->remotePath($name), [], $data);
    }

    protected function listFiles($prefix)
    {
        $files = [];
        $query = ['list-type' => '2', 'prefix' => $this->remotePath($prefix)];
        do {
            $xml = simplexml_load_string($this->request('GET', '', $query));
            if ($xml === false) {
                throw new \Exception(gettext('S3: invalid ListObjectsV2 response'));
            }
            foreach ($xml->Contents as $item) {
                $files[] = basename((string)$item->Key);
            }
            $query['continuation-token'] = (string)$xml->NextContinuationToken;
        } while ((string)$xml->IsTruncated === 'true');
        return $files;
    }

    protected function remove($name)
    {
        $this->request('DELETE', $this->remotePath($name));
    }

    /**
     * signed request to the bucket
     * @param string $method HTTP method
     * @param string $key object key, empty for the bucket itself
     * @param array $query query parameters
     * @param string $body request body
     * @param int|null $now unix time, only set by tests
     * @return string response body
     * @throws \Exception on network or HTTP errors
     */
    public function request($method, $key, array $query = [], $body = '', $now = null)
    {
        $mdl = $this->mdl();
        $endpoint = rtrim($mdl->endpoint->getValue(), '/');
        $region = $mdl->region->getValue();
        $url = parse_url($endpoint);
        $host = $url['host'] . (isset($url['port']) ? ':' . $url['port'] : '');
        $uri = '/' . rawurlencode($mdl->bucket->getValue());
        if ($key !== '') {
            $uri .= '/' . str_replace('%2F', '/', rawurlencode($key));
        }
        ksort($query);
        $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $amzdate = gmdate('Ymd\THis\Z', $now ?? time());
        $date = substr($amzdate, 0, 8);
        $hash = hash('sha256', $body);
        $signed = 'host;x-amz-content-sha256;x-amz-date';
        $canonical = "{$method}\n{$uri}\n{$qs}\nhost:{$host}\nx-amz-content-sha256:{$hash}\nx-amz-date:{$amzdate}\n\n" .
            "{$signed}\n{$hash}";
        $scope = "{$date}/{$region}/s3/aws4_request";
        $tosign = "AWS4-HMAC-SHA256\n{$amzdate}\n{$scope}\n" . hash('sha256', $canonical);
        $kdf = 'AWS4' . $mdl->secret_key->getValue();
        foreach ([$date, $region, 's3', 'aws4_request'] as $part) {
            $kdf = hash_hmac('sha256', $part, $kdf, true);
        }
        $auth = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $mdl->access_key->getValue(),
            $scope,
            $signed,
            hash_hmac('sha256', $tosign, $kdf)
        );

        $ch = curl_init($endpoint . $uri . ($qs !== '' ? "?{$qs}" : ''));
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => [
                "Host: {$host}",
                "x-amz-content-sha256: {$hash}",
                "x-amz-date: {$amzdate}",
                "Authorization: {$auth}",
                'Content-Type: application/octet-stream',
            ],
        ]);
        if ($method == 'PUT') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($response === false || $code < 200 || $code >= 300) {
            throw new \Exception(sprintf('S3 %s %s failed: HTTP %d %s %s', $method, $uri, $code, $error, $response));
        }
        return $response;
    }
}
