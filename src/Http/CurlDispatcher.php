<?php

namespace Embed\Http;

use Embed\Exceptions\EmbedException;
use stdClass;

/**
 * Curl dispatcher.
 */
class CurlDispatcher implements DispatcherInterface
{
    private $responses = [];

    private $config = [
        CURLOPT_MAXREDIRS => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_ENCODING => '',
        CURLOPT_AUTOREFERER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0',
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
    ];

    /**
     * Constructor.
     *
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        $this->config = $config + $this->config;

        if (!isset($this->config[CURLOPT_COOKIEJAR])) {
            $cookies = str_replace('//', '/', sys_get_temp_dir().'/embed-cookies.'.uniqid());

            if (is_file($cookies)) {
                if (!is_writable($cookies)) {
                    throw new EmbedException(sprintf('The temporary cookies file "%s" is not writable', $cookies));
                }
            } elseif (!is_writable(dirname($cookies))) {
                throw new EmbedException(sprintf('The temporary folder "%s" is not writable', dirname($cookies)));
            }

            $this->config[CURLOPT_COOKIEJAR] = $cookies;
            $this->config[CURLOPT_COOKIEFILE] = $cookies;
        }
    }

    /**
     * Return all responses for debug purposes
     *
     * @return AbstractResponse[]
     */
    public function getAllResponses()
    {
        return $this->responses;
    }

    /**
     * Remove the cookies file on destruct the instance.
     */
    public function __destruct()
    {
        $cookies = $this->config[CURLOPT_COOKIEJAR];

        if (is_file($cookies)) {
            unlink($cookies);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function dispatch(Url $url)
    {
        $connection = curl_init((string) $url);
        curl_setopt_array($connection, $this->config);

        $curl = new CurlResult($connection);

        //Get only text responses
        $curl->onHeader(function ($name, $value, $data) {
            if ($name === 'content-type') {
                $data->isBinary = !preg_match('/(text|html|json)/', strtolower($value));
            }
        });

        $curl->onBody(function ($string, stdClass $data) {
            return !$data->isBinary;
        });

        curl_exec($connection);

        $result = $curl->getResult();

        curl_close($connection);

        return $this->responses[] = new Response(
            $url,
            Url::create($result['url']),
            $result['statusCode'],
            $result['contentType'],
            $result['content'],
            $result['headers'],
            $result['info']
        );
    }

    /**
     * {@inheritdoc}
     */
    public function dispatchImages(array $urls)
    {
        if (empty($urls)) {
            return [];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimetypes = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/bmp',
            'image/x-icon',
        ];
        $curl_multi = curl_multi_init();
        $responses = [];
        $connections = [];

        foreach ($urls as $k => $url) {
            if ($url->getScheme() === 'data') {
                $response = ImageResponse::createFromBase64($url);

                if ($response) {
                    $responses[$k] = $response;
                }

                continue;
            }

            $connection = curl_init((string) $url);

            curl_setopt_array($connection, $this->config);
            curl_multi_add_handle($curl_multi, $connection);

            $curl = new CurlResult($connection);

            $curl->onBody(function ($body, stdClass $data) use ($finfo, $mimetypes) {
                if (empty($data->mime)) {
                    $data->mime = finfo_buffer($finfo, $body);

                    if (!in_array($data->mime, $mimetypes, true)) {
                        $data->mime = null;

                        return false;
                    }
                }

                if (($info = getimagesizefromstring($body))) {
                    $data->width = $info[0];
                    $data->height = $info[1];

                    return false;
                }
            });

            $connections[$k] = $curl;
        }

        if (!empty($connections)) {
            do {
                $return = curl_multi_exec($curl_multi, $active);
            } while ($return === CURLM_CALL_MULTI_PERFORM);

            while ($active && $return === CURLM_OK) {
                if (curl_multi_select($curl_multi) === -1) {
                    usleep(100);
                }

                do {
                    $return = curl_multi_exec($curl_multi, $active);
                } while ($return === CURLM_CALL_MULTI_PERFORM);
            }

            foreach ($connections as $k => $connection) {
                $resource = $connection->getResource();

                curl_multi_remove_handle($curl_multi, $resource);
                $result = $connection->getResult();

                if (!empty($result['data']->mime)) {
                    $responses[$k] = $this->responses[] = new ImageResponse(
                        $urls[$k],
                        Url::create($result['url']),
                        $result['statusCode'],
                        $result['contentType'],
                        [$result['data']->width, $result['data']->height],
                        $result['headers'],
                        $result['info']
                    );
                }
            }
        }

        finfo_close($finfo);
        curl_multi_close($curl_multi);

        ksort($responses, SORT_NUMERIC);

        return array_values($responses);
    }
}
