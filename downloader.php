<?php

declare(strict_types=1);

use function var_dump as d;

require_once('vendor/autoload.php');

$username = file_get_contents('photobucket_username.txt');
$password = file_get_contents('photobucket_password.txt');
if (!$username || !$password) {
    echo "Please create photobucket_username.txt and photobucket_password.txt\n";
    exit(1);
}
$username = trim($username);
$password = trim($password);
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(
    function ($severity, $message, $file, $line) {
        if (error_reporting() & $severity) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        }
    }
);
function dd(...$args)
{
    $trace = debug_backtrace()[0];
    echo "dd at {$trace['file']}:{$trace['line']}\n";
    var_dump(...$args);
    exit(1);
}
function retryUntilTruthy(callable $func, float $timeout = 10.0, float $interval = 0.1)
{
    $start = microtime(true);
    while (true) {
        try {
            $ret = $func();
            if ($ret) {
                return $ret;
            }
        } catch (\Throwable $e) {
            // ignore
        }
        $t = microtime(true);
        if ($t - $start > $timeout) {
            if ($e) {
                throw $e;
            }
            throw new RuntimeException("Timeout: $timeout");
        }
        usleep((int)($interval * 1000000));
    }
}
class Photobucket
{
    private $browser;
    private $page;
    private $internal_username; // May be different from login username
    private $internal_bucket_id;
    function __construct()
    {
        $factory = new \HeadlessChromium\BrowserFactory();
        $browser = $factory->createBrowser(
            [
                'headless' => false,
                'noSandbox' => true,
                'sendSyncDefaultTimeout' => 10 * 1000,
                'customFlags' => [
                    '--no-sandbox',
                    '--no-zygote',
                    '--single-process',
                    '--disable-gpu-sandbox',
                    '--disable-setuid-sandbox',
                ],
            ]
        );
        $page = $browser->createPage();
        $this->browser = $browser;
        $this->page = $page;
    }
    function login(
        #[\SensitiveParameter]
        string $username,
        #[\SensitiveParameter]
        string $password
    ) {
        $page = &$this->page;
        $page->navigate('https://app.photobucket.com/login')->waitForNavigation(
            \HeadlessChromium\Page::NETWORK_IDLE
        );
        $page->mouse()->find("#username")->click();
        $page->keyboard()->typeText($username);
        $page->mouse()->find("#password")->click();
        $page->keyboard()->typeText($password);
        $page->evaluate('document.querySelector("button[type=submit]").click()')->getReturnValue();
        try {
            $page->waitForReload(\HeadlessChromium\Page::NETWORK_IDLE, 10000);
        } catch (\Exception $e) {
            // ignore
        }
        // <div class="MuiAlert-message css-1xsto0d"><div class="MuiTypography-root MuiTypography-body1 MuiTypography-gutterBottom MuiAlertTitle-root jss20 jss21 css-vhidae">Error logging in</div>Invalid email/username or password</div>
        $error = $page->evaluate('document.querySelector(".MuiAlert-message")?.textContent')->getReturnValue();
        uglyjump:
        if ($error) {
            echo ("Error logging in: $error");
            echo "You probably hit a ReCaptcha challenge.\n";
            echo "You must solve the ReCaptcha, log in manually, then press enter in this terminal.\n";
            stream_set_blocking(STDIN, true);
            var_dump(fgets(STDIN));
        }
        // JSON.parse(localStorage.getItem("__user_id"))
        $code = 'JSON.parse(localStorage.getItem("__user_id"));';
        $internal_username = $page->evaluate($code)->getReturnValue();
        if (is_string($internal_username)) {
            $internal_username = trim($internal_username);
        }
        if (empty($internal_username)) {
            $error = "Failed to get internal username: $code -> " . var_export($internal_username, true);
            goto uglyjump;
            //throw new Exception("Failed to get internal username: $code -> " . var_export($internal_username, true));
        }
        $this->internal_username = $internal_username;
        echo "internal_username: {$this->internal_username}\n";
        $code = 'document.querySelector("#appbar-current-bucket").href.match(/bucket\/([^\/]*)/)[1];';
        $internal_bucket_id = $page->evaluate($code)->getReturnValue();
        if (is_string($internal_bucket_id)) {
            $internal_bucket_id = trim($internal_bucket_id);
        }
        if (empty($internal_bucket_id)) {
            throw new Exception("Failed to get internal bucket id: $code -> " . var_export($internal_bucket_id, true));
        }
        echo "internal_bucket_id: {$internal_bucket_id}\n";
        $this->internal_bucket_id = $internal_bucket_id;
    }


    function downloadEverything(): void
    {
        $ret = [];
        $page = &$this->page;
        // document.cookie 'cwr_u=; _gid=GA1.2.1666773935.1705050541; _fbp=fb.1.1705050541028.700942932; _tt_enable_cookie=1; _ttp=KsD1j70yQE35rPrmL8O1Mz0Zg1-; _pin_unauth=dWlkPVpEVTBPRFl3WldZdFpUVmhaaTAwTVRkaUxXRXlZbUV0TXpReFpEUmtNMkkyT1dObA; __hstc=35533630.673d82828e43783dc135d49f531c15a7.1705050543222.1705050543222.1705050543222.1; hubspotutk=673d82828e43783dc135d49f531c15a7; __hssrc=1; _gcl_au=1.1.714453139.1705050541.1346485070.1705050543.1705050543; app_auth=eyJhbGciOiJSUzI1NiIsImtpZCI6IjdjZjdmODcyNzA5MWU0Yzc3YWE5OTVkYjYwNzQzYjdkZDJiYjcwYjUiLCJ0eXAiOiJKV1QifQ.eyJuYW1lIjoiSGFucyBIZW5yaWsgQmVyZ2FuIiwicGljdHVyZSI6Imh0dHBzOi8vbGgzLmdvb2dsZXVzZXJjb250ZW50LmNvbS9hL0FHTm15eFlSX1drd1MtbV9WUjZLQUlSRWJUeWZUT2E5NGh0QVlGMGVORjZIPXM5Ni1jIiwiaXNHcm91cFVzZXIiOnRydWUsInBhc3N3b3JkRXhwaXJlZCI6ZmFsc2UsImlzcyI6Imh0dHBzOi8vc2VjdXJldG9rZW4uZ29vZ2xlLmNvbS9waG90b2J1Y2tldC1tb2JpbGUtYXBwcyIsImF1ZCI6InBob3RvYnVja2V0LW1vYmlsZS1hcHBzIiwiYXV0aF90aW1lIjoxNzA1MDUwNTQzLCJ1c2VyX2lkIjoiNHg0bm9yd2F5Iiwic3ViIjoiNHg0bm9yd2F5IiwiaWF0IjoxNzA1MDUwNTQzLCJleHAiOjE3MDUwNTQxNDMsImVtYWlsIjoiZGl2aW5pdHk3NkBnbWFpbC5jb20iLCJlbWFpbF92ZXJpZmllZCI6dHJ1ZSwiZmlyZWJhc2UiOnsiaWRlbnRpdGllcyI6eyJlbWFpbCI6WyJkaXZpbml0eTc2QGdtYWlsLmNvbSJdfSwic2lnbl9pbl9wcm92aWRlciI6ImN1c3RvbSJ9fQ.ffaGZ_cyh10xLVGo1KOD5NvWxwGpIRIS8521G-iKYX5kwF8kvnXGl_TJXI63sPamHkVODHGCmoVl143N7u-rOILfEMOuJsLWWCkc0TUp-KdnwffX__seGh4QuKg4gWN7jjU7tmRXIVGbPkk_0jaeciulM2-YtzlUR0AvDZ_O7LrPTUueaCJxTpiQd1q5fgyyvxqxQPysX9QkANkcvRNLBFO7D0DCmgg2aJdb-c5QPzgWTdao1kp9TMmRUh8EbzR43pS8ZCZR_KQXNs52MnI8o6e-9omyrvIgjKelznlx9IenZIR0jQd4US6TCtIkSnI8hizpm2vdEloCLTCZIOMTEg; _uetsid=39b43650b12a11eebf3d1b2e4684110f; _uetvid=39b42640b12a11eea19de38ab0318a25; _ga=GA1.2.1556083939.1705050541; _ga_Y2Z30LCFMB=GS1.1.1705050540.1.1.1705050546.54.0.0; __hssc=35533630.3.1705050543222'
        // /app_auth=([^;]+)/.exec(document.cookie)[1]
        // https://app.photobucket.com/u/4x4norway/albums
        if (0) {
            $page->navigate("https://app.photobucket.com/u/{$this->internal_username}/albums")->waitForNavigation(
                \HeadlessChromium\Page::DOM_CONTENT_LOADED
            );
        }
        $get_path_of_album_id = function (?string $album_id): string {
            $ret = __DIR__ . DIRECTORY_SEPARATOR . "downloads" . DIRECTORY_SEPARATOR;
            if ($album_id === null) {
                return $ret . "no_album_id" . DIRECTORY_SEPARATOR;
            }
            // TODO: implement this
            // Photobucket made major changes to how folders/directories/paths are handled, and 
            // I don't have time to figure it out right now.
            return $ret . "todo_fix_path_of_album_id_$album_id" . DIRECTORY_SEPARATOR;
        };
        $get_images_of_bucket_id = function (string $bucket_id) use (&$page): array {
            $duplicateUrlList = [];
            $extractedImages = [];
            $iteration = 0;
            $nextToken = "";
            for (;;) {
                ++$iteration;
                $authToken = $page->evaluate('/app_auth=([^;]+)/.exec(document.cookie)[1]')->getReturnValue();
                $url = "https://app.photobucket.com/api/graphql/v2";
                $headers = array(
                    "apollographql-client-name" => "photobucket-web",
                    "apollographql-client-version" => "1.282.0",
                    "authorization" => $authToken,
                    "content-type" => "application/json",
                    // ignore: "x-amzn-trace-id": "Root=1-67fa629b-89a5f0674486adea4229805f;Parent=ae191bcc73df5def;Sampled=1",
                    // ignore: "x-correlation-id": "4c67becd-b07e-4b0a-a89f-bae39150882a"
                );
                $body = array(
                    'operationName' => 'BucketMediaByAlbumId',
                    'variables' =>
                    array(
                        'limit' => 1000,
                        'bucketId' => $bucket_id,
                        'filterBy' => NULL,
                        'sortBy' =>
                        array(
                            'order' => 'DESC',
                            'field' => 'DATE_TAKEN',
                        ),
                        'nextToken' => $nextToken, // 'eyJpZCI6ImNiN2VhZGYyLTJkYTItNDkwNC1hMzRjLWIyNzJiYmM3OGZlMiIsImJ1Y2tldElkIjoiM2QyYTAyY2EtMzNlMi00MjkyLWE3MmYtZmM2NWRkYWIzNTZiIiwic3RhdHVzVHlwZVNLIjoiQUNUSVZFI01FRElBIzIwMTAtMTItMjRUMDM6MjA6MjguMDAwWiJ9',
                    ),
                    'query' => 'query BucketMediaByAlbumId($bucketId: ID!, $albumId: ID, $limit: Int! = 40, $nextToken: String, $filterBy: BucketMediaFilter, $sortBy: BucketMediaSorter) {
                bucketMediaByAlbumId(
                  bucketId: $bucketId
                  albumId: $albumId
                  limit: $limit
                  nextToken: $nextToken
                  filterBy: $filterBy
                  sortBy: $sortBy
                ) {
                  items {
                    ...BucketMediaFragment
                    __typename
                  }
                  nextToken
                  __typename
                }
              }
              
              fragment BucketMediaFragment on BucketMedia {
                albumId
                bucketId
                createdAt
                dateTaken
                description
                filename
                fileSize
                height
                id
                imageUrl
                isBanned
                isVideo
                mediaType
                scheduledDeletionAt
                originalFilename
                title
                userId
                userTags
                width
                isBanned
                isFavorite
                __typename
              }',
                );
                $js = 'xhr= new XMLHttpRequest();' . "\n";
                $encode = function ($data): string {
                    return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                };
                $js .= 'xhr.open("POST", ' . $encode($url) . ', false);' . "\n";
                foreach ($headers as $key => $value) {
                    $js .= 'xhr.setRequestHeader(' . $encode($key) . ', ' . $encode($value) . ');' . "\n";
                }
                $js .= 'xhr.send(' . $encode($encode($body)) . ');' . "\n";
                $js .= 'xhr.responseText;';
                echo "sending: $js\niteration: $iteration\nresults-so-far: " . count($extractedImages) . "\n";
                $parsed = $page->evaluate($js)->getReturnValue();
                $parsed = json_decode($parsed, true, 512, JSON_THROW_ON_ERROR);
                if (false) {
                    $parsed = array(
                        'data' =>
                        array(
                            'bucketMediaByAlbumId' =>
                            array(
                                'items' =>
                                array(
                                    0 =>
                                    array(
                                        'albumId' => 'e617f4e9-d2f9-4b2c-91e4-38e17e383552',
                                        'bucketId' => '3d2a02ca-33e2-4292-a72f-fc65ddab356b',
                                        'createdAt' => '2010-12-24T03:20:27.000Z',
                                        'dateTaken' => '2010-12-24T03:20:27.000Z',
                                        'description' => NULL,
                                        'filename' => '11778e47-d2ee-4c3b-b78d-74169ad33324.png',
                                        'fileSize' => 679,
                                        'height' => 32,
                                        'id' => '11778e47-d2ee-4c3b-b78d-74169ad33324',
                                        'imageUrl' => 'https://hosting.photobucket.com/3d2a02ca-33e2-4292-a72f-fc65ddab356b/11778e47-d2ee-4c3b-b78d-74169ad33324.png',
                                        'isBanned' => false,
                                        'isVideo' => false,
                                        'mediaType' => 'image/png',
                                        'scheduledDeletionAt' => NULL,
                                        'originalFilename' => '22171002.png',
                                        'title' => '22171002',
                                        'userId' => 'msmeovv',
                                        'userTags' => NULL,
                                        'width' => 32,
                                        'isFavorite' => false,
                                        '__typename' => 'BucketMedia',
                                    ),
                                    1 =>
                                    array(
                                        'albumId' => 'e617f4e9-d2f9-4b2c-91e4-38e17e383552',
                                        'bucketId' => '3d2a02ca-33e2-4292-a72f-fc65ddab356b',
                                        'createdAt' => '2010-12-24T03:20:26.000Z',
                                        'dateTaken' => '2010-12-24T03:20:26.000Z',
                                        'description' => NULL,
                                        'filename' => 'eeac5c02-7db3-45d7-bc65-0defd92855bf.gif',
                                        'fileSize' => 115716,
                                        'height' => 243,
                                        'id' => 'eeac5c02-7db3-45d7-bc65-0defd92855bf',
                                        'imageUrl' => 'https://hosting.photobucket.com/3d2a02ca-33e2-4292-a72f-fc65ddab356b/eeac5c02-7db3-45d7-bc65-0defd92855bf.gif',
                                        'isBanned' => false,
                                        'isVideo' => false,
                                        'mediaType' => 'image/gif',
                                        'scheduledDeletionAt' => NULL,
                                        'originalFilename' => '22171000affected.gif',
                                        'title' => '22171000affected',
                                        'userId' => 'msmeovv',
                                        'userTags' => NULL,
                                        'width' => 234,
                                        'isFavorite' => false,
                                        '__typename' => 'BucketMedia',
                                    ),
                                    2 =>
                                    array(
                                        'albumId' => 'e617f4e9-d2f9-4b2c-91e4-38e17e383552',
                                        'bucketId' => '3d2a02ca-33e2-4292-a72f-fc65ddab356b',
                                        'createdAt' => '2010-12-24T03:20:25.000Z',
                                        'dateTaken' => '2010-12-24T03:20:25.000Z',
                                        'description' => NULL,
                                        'filename' => '9612d68d-54fb-4993-a281-e1719b5ec1e5.png',
                                        'fileSize' => 669,
                                        'height' => 32,
                                        'id' => '9612d68d-54fb-4993-a281-e1719b5ec1e5',
                                        'imageUrl' => 'https://hosting.photobucket.com/3d2a02ca-33e2-4292-a72f-fc65ddab356b/9612d68d-54fb-4993-a281-e1719b5ec1e5.png',
                                        'isBanned' => false,
                                        'isVideo' => false,
                                        'mediaType' => 'image/png',
                                        'scheduledDeletionAt' => NULL,
                                        'originalFilename' => '22171000.png',
                                        'title' => '22171000',
                                        'userId' => 'msmeovv',
                                        'userTags' => NULL,
                                        'width' => 32,
                                        'isFavorite' => false,
                                        '__typename' => 'BucketMedia',
                                    ),
                                    3 =>
                                    array(
                                        'albumId' => 'e617f4e9-d2f9-4b2c-91e4-38e17e383552',
                                        'bucketId' => '3d2a02ca-33e2-4292-a72f-fc65ddab356b',
                                        'createdAt' => '2010-12-24T03:20:24.000Z',
                                        'dateTaken' => '2010-12-24T03:20:24.000Z',
                                        'description' => NULL,
                                        'filename' => '9be06988-71cd-4404-b741-bc8a21d74020.png',
                                        'fileSize' => 548,
                                        'height' => 32,
                                        'id' => '9be06988-71cd-4404-b741-bc8a21d74020',
                                        'imageUrl' => 'https://hosting.photobucket.com/3d2a02ca-33e2-4292-a72f-fc65ddab356b/9be06988-71cd-4404-b741-bc8a21d74020.png',
                                        'isBanned' => false,
                                        'isVideo' => false,
                                        'mediaType' => 'image/png',
                                        'scheduledDeletionAt' => NULL,
                                        'originalFilename' => '22170001.png',
                                        'title' => '22170001',
                                        'userId' => 'msmeovv',
                                        'userTags' => NULL,
                                        'width' => 32,
                                        'isFavorite' => false,
                                        '__typename' => 'BucketMedia',
                                    ),
                                    // etc 40 items
                                ),
                                'nextToken' => 'eyJpZCI6ImY5NmJiOTA4LWQ4Y2EtNDgxZS05ZWFhLTA5NmEwZDkwNWQ5YSIsImJ1Y2tldElkIjoiM2QyYTAyY2EtMzNlMi00MjkyLWE3MmYtZmM2NWRkYWIzNTZiIiwic3RhdHVzVHlwZVNLIjoiQUNUSVZFI01FRElBIzIwMTAtMTItMjRUMDM6MDE6MTcuMDAwWiJ9',
                                '__typename' => 'BucketMediaResults',
                            ),
                        ),
                    );
                }
                foreach ($parsed['data']['bucketMediaByAlbumId']['items'] as $item) {
                    $url = $item['imageUrl'];
                    if (isset($duplicateUrlList[$url])) {
                        continue;
                    }
                    $duplicateUrlList[$url] = true;
                    $extractedImages[] = [
                        "imageUrl" => $item['imageUrl'],
                        "dateTaken" => $item['dateTaken'],
                        "albumId" => $item['albumId'], // need it to find the folder path in the future..
                        "originalFilename" => $item['originalFilename'],
                    ];
                }
                $nextToken = $parsed['data']['bucketMediaByAlbumId']['nextToken'] ?? "";
                if (empty($nextToken)) {
                    return $extractedImages;
                }
            }
        };
        $images = $get_images_of_bucket_id($this->internal_bucket_id);
        $downloader_ch = curl_init();
        curl_setopt_array($downloader_ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_USERAGENT => "curl/" . curl_version()['version'],
            CURLOPT_ENCODING => "",
        ));
        foreach ($images as $image) {
            var_dump($image);
            $path = $get_path_of_album_id($image['albumId']);
            if (!is_dir($path)) {
                if (!mkdir($path, 0777, true)) {
                    throw new Exception("Failed to create directory: $path - " . var_export(error_get_last(), true));
                }
                // set creation/modification time to the date taken
                $date = strtotime($image['dateTaken']);
                if ($date === false) {
                    throw new Exception("Failed to parse date: {$image['dateTaken']}");
                }
                if (!touch($path, $date, $date)) {
                    throw new Exception("Failed to set date on directory: $path - " . var_export(error_get_last(), true));
                }
            }
            $filepath = $path . DIRECTORY_SEPARATOR . $image['originalFilename'];
            if (file_exists($filepath)) {
                // because we haven't gotten folder code working yet, duplicate filenames are possible
                for ($i = 0; ++$i;) {
                    $new_filepath = $path . "/" . $i . "_" . $image['originalFilename'];
                    if (!file_exists($new_filepath)) {
                        $filepath = $new_filepath;
                        break;
                    }
                    if ($i > 9999) {
                        throw new Exception("Failed to find unique filename: $filepath - " . var_export(error_get_last(), true));
                    }
                }
            }
            $url = $image['imageUrl'];
            echo "Downloading $url to $filepath\n";
            curl_setopt($downloader_ch, CURLOPT_URL, $url);
            $binary = curl_exec($downloader_ch);
            if (curl_errno($downloader_ch)) {
                throw new Exception("Failed to download image: $url: " . curl_errno($downloader_ch) . ": " . curl_error($downloader_ch) . " - " . curl_strerror(curl_errno($downloader_ch)));
            }
            file_put_contents($filepath, $binary, LOCK_EX);
            $date = strtotime($image['dateTaken']);
            if ($date === false) {
                throw new Exception("Failed to parse date: {$image['dateTaken']}");
            }
            if (!touch($filepath, $date, $date)) {
                throw new Exception("Failed to set date on file: $filepath - " . var_export(error_get_last(), true));
            }
        }
    }
    function downloadFolder(array $folder_data)
    {
        $id = $folder_data['id'];
        /*
curl 'https://app.photobucket.com/api/graphql/v2' \
  -H 'authority: app.photobucket.com' \
  -H 'accept: * /*' \
  -H 'accept-language: en-US,en;q=0.9' \
  -H 'apollographql-client-name: photobucket-web' \
  -H 'apollographql-client-version: 1.172.0' \
  -H 'authorization: eyJhbGciOiJSUzI1NiIsImtpZCI6IjdjZjdmODcyNzA5MWU0Yzc3YWE5OTVkYjYwNzQzYjdkZDJiYjcwYjUiLCJ0eXAiOiJKV1QifQ.eyJuYW1lIjoiSGFucyBIZW5yaWsgQmVyZ2FuIiwicGljdHVyZSI6Imh0dHBzOi8vbGgzLmdvb2dsZXVzZXJjb250ZW50LmNvbS9hL0FHTm15eFlSX1drd1MtbV9WUjZLQUlSRWJUeWZUT2E5NGh0QVlGMGVORjZIPXM5Ni1jIiwiaXNHcm91cFVzZXIiOnRydWUsInBhc3N3b3JkRXhwaXJlZCI6ZmFsc2UsImlzcyI6Imh0dHBzOi8vc2VjdXJldG9rZW4uZ29vZ2xlLmNvbS9waG90b2J1Y2tldC1tb2JpbGUtYXBwcyIsImF1ZCI6InBob3RvYnVja2V0LW1vYmlsZS1hcHBzIiwiYXV0aF90aW1lIjoxNzA1MDU2MjY4LCJ1c2VyX2lkIjoiNHg0bm9yd2F5Iiwic3ViIjoiNHg0bm9yd2F5IiwiaWF0IjoxNzA1MDU2MjY4LCJleHAiOjE3MDUwNTk4NjgsImVtYWlsIjoiZGl2aW5pdHk3NkBnbWFpbC5jb20iLCJlbWFpbF92ZXJpZmllZCI6dHJ1ZSwiZmlyZWJhc2UiOnsiaWRlbnRpdGllcyI6eyJlbWFpbCI6WyJkaXZpbml0eTc2QGdtYWlsLmNvbSJdfSwic2lnbl9pbl9wcm92aWRlciI6ImN1c3RvbSJ9fQ.MwBYKprMzCpdsop_YVGsWLKjqDLjLYnWRAwoXflInBHM_mOAOKliM2BitcJ-ttQhrXtiDv-oQBprgsz4bbHp2Pvu0eAKqG2Jtez3j6WmTx9Zj5D8GJwUlP0dbfaSmHKY8drf94kvwfg-VgQjQsXpaGFufmPOQjqxKNpKD4JuqjGgQLsccdCNgKo0VzviLNcFt0IMHI8yU4Q1vm5kU91fw9POkNBBQ_q33kb3XZ1FWc7dSsmIfhXDa7yFQPicOmeZ-2mwdRb9euPnKFomfxakO9mDYR0lFhPZtIztUt2IiRPq_PbEIu3ZPXpWtAydixC9oidoDl4nzJCAMsbNekzDDg' \
  -H 'content-type: application/json' \
  -H 'cookie: cwr_u=; _gid=GA1.2.986437771.1705056267; _fbp=fb.1.1705056267330.443504285; _tt_enable_cookie=1; _ttp=QWFWjCVlgV9vJojhk2cdZhIyYXP; _pin_unauth=dWlkPVpUZGxOakV4TW1ZdE5tTm1ZUzAwTXpKaUxUaGpZamd0WVdabU5tUXpZVGRtWVdObQ; __hstc=35533630.21ac2d42b68237d2b6d619f02705677d.1705056267969.1705056267969.1705056267969.1; hubspotutk=21ac2d42b68237d2b6d619f02705677d; __hssrc=1; _gcl_au=1.1.1669398060.1705056267.1658921598.1705056268.1705056268; app_auth=eyJhbGciOiJSUzI1NiIsImtpZCI6IjdjZjdmODcyNzA5MWU0Yzc3YWE5OTVkYjYwNzQzYjdkZDJiYjcwYjUiLCJ0eXAiOiJKV1QifQ.eyJuYW1lIjoiSGFucyBIZW5yaWsgQmVyZ2FuIiwicGljdHVyZSI6Imh0dHBzOi8vbGgzLmdvb2dsZXVzZXJjb250ZW50LmNvbS9hL0FHTm15eFlSX1drd1MtbV9WUjZLQUlSRWJUeWZUT2E5NGh0QVlGMGVORjZIPXM5Ni1jIiwiaXNHcm91cFVzZXIiOnRydWUsInBhc3N3b3JkRXhwaXJlZCI6ZmFsc2UsImlzcyI6Imh0dHBzOi8vc2VjdXJldG9rZW4uZ29vZ2xlLmNvbS9waG90b2J1Y2tldC1tb2JpbGUtYXBwcyIsImF1ZCI6InBob3RvYnVja2V0LW1vYmlsZS1hcHBzIiwiYXV0aF90aW1lIjoxNzA1MDU2MjY4LCJ1c2VyX2lkIjoiNHg0bm9yd2F5Iiwic3ViIjoiNHg0bm9yd2F5IiwiaWF0IjoxNzA1MDU2MjY4LCJleHAiOjE3MDUwNTk4NjgsImVtYWlsIjoiZGl2aW5pdHk3NkBnbWFpbC5jb20iLCJlbWFpbF92ZXJpZmllZCI6dHJ1ZSwiZmlyZWJhc2UiOnsiaWRlbnRpdGllcyI6eyJlbWFpbCI6WyJkaXZpbml0eTc2QGdtYWlsLmNvbSJdfSwic2lnbl9pbl9wcm92aWRlciI6ImN1c3RvbSJ9fQ.MwBYKprMzCpdsop_YVGsWLKjqDLjLYnWRAwoXflInBHM_mOAOKliM2BitcJ-ttQhrXtiDv-oQBprgsz4bbHp2Pvu0eAKqG2Jtez3j6WmTx9Zj5D8GJwUlP0dbfaSmHKY8drf94kvwfg-VgQjQsXpaGFufmPOQjqxKNpKD4JuqjGgQLsccdCNgKo0VzviLNcFt0IMHI8yU4Q1vm5kU91fw9POkNBBQ_q33kb3XZ1FWc7dSsmIfhXDa7yFQPicOmeZ-2mwdRb9euPnKFomfxakO9mDYR0lFhPZtIztUt2IiRPq_PbEIu3ZPXpWtAydixC9oidoDl4nzJCAMsbNekzDDg; cookieconsent_status=dismiss; _gat_UA-245455-50=1; _uetsid=8e8b1480b13711ee8afa85b2803ef3d3; _uetvid=8e8b1a70b13711eea38643aed9da9269; _ga=GA1.2.373582822.1705056267; _ga_Y2Z30LCFMB=GS1.1.1705056266.1.1.1705056361.38.0.0; __hssc=35533630.6.1705056267970' \
  -H 'origin: https://app.photobucket.com' \
  -H 'referer: https://app.photobucket.com/u/4x4norway/a/706bbe20-ad5c-402f-964b-b51d8b714c40' \
  -H 'sec-ch-ua: "Not_A Brand";v="8", "Chromium";v="120"' \
  -H 'sec-ch-ua-mobile: ?0' \
  -H 'sec-ch-ua-platform: "Linux"' \
  -H 'sec-fetch-dest: empty' \
  -H 'sec-fetch-mode: cors' \
  -H 'sec-fetch-site: same-origin' \
  -H 'user-agent: Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36' \
  -H 'x-correlation-id: 0b2521f6-4c62-4132-8fe0-624f2193fd39' \
  --data-raw $'{"operationName":"GetAlbumImagesV2","variables":{"albumId":"706bbe20-ad5c-402f-964b-b51d8b714c40","sortBy":{"field":"DATE","desc":false},"pageSize":40},"query":"query GetAlbumImagesV2($albumId: String\u0021, $pageSize: Int, $scrollPointer: String, $sortBy: Sorter) {\\n  getAlbumImagesV2(\\n    albumId: $albumId\\n    pageSize: $pageSize\\n    scrollPointer: $scrollPointer\\n    sortBy: $sortBy\\n  ) {\\n    scrollPointer\\n    items {\\n      ...MediaFragment\\n      __typename\\n    }\\n    __typename\\n  }\\n}\\n\\nfragment MediaFragment on Image {\\n  id\\n  title\\n  dateTaken\\n  uploadDate\\n  isVideoType\\n  username\\n  isBlurred\\n  nsfw\\n  status\\n  image {\\n    width\\n    size\\n    height\\n    url\\n    isLandscape\\n    exif {\\n      longitude\\n      eastOrWestLongitude\\n      latitude\\n      northOrSouthLatitude\\n      altitude\\n      altitudeRef\\n      cameraBrand\\n      cameraModel\\n      __typename\\n    }\\n    __typename\\n  }\\n  thumbnailImage {\\n    width\\n    size\\n    height\\n    url\\n    isLandscape\\n    __typename\\n  }\\n  originalImage {\\n    width\\n    size\\n    height\\n    url\\n    isLandscape\\n    __typename\\n  }\\n  livePhoto {\\n    width\\n    size\\n    height\\n    url\\n    isLandscape\\n    __typename\\n  }\\n  albumId\\n  description\\n  userTags\\n  clarifaiTags\\n  uploadDate\\n  originalFilename\\n  isMobileUpload\\n  albumName\\n  attributes\\n  deletionState {\\n    deletedAt\\n    __typename\\n  }\\n  __typename\\n}"}' \
  --compressed
*/
        $page = &$this->page;
        $page->navigate("https://app.photobucket.com/u/{$this->internal_username}/a/$id")->waitForNavigation(
            \HeadlessChromium\Page::DOM_CONTENT_LOADED
        );
        $authToken = $page->evaluate('/app_auth=([^;]+)/.exec(document.cookie)[1]')->getReturnValue();
        $data = [
            'operationName' => 'GetAlbumImagesV2',
            'variables' => [
                'albumId' => $folder_data['id'], //'706bbe20-ad5c-402f-964b-b51d8b714c40',
                'sortBy' => [
                    'field' => 'DATE',
                    'desc' => false,
                ],
                'pageSize' => 999999, // 3,
            ],
            'query' => 'query GetAlbumImagesV2($albumId: String!, $pageSize: Int, $scrollPointer: String, $sortBy: Sorter) {
                getAlbumImagesV2(
                    albumId: $albumId
                    pageSize: $pageSize
                    scrollPointer: $scrollPointer
                    sortBy: $sortBy
                ) {
                    scrollPointer
                    items {
                        ...MediaFragment
                        __typename
                    }
                    __typename
                }
            }
        
            fragment MediaFragment on Image {
                id
                title
                dateTaken
                // uploadDate
                // isVideoType
                // username
                // isBlurred
                // nsfw
                status
                image {
                    // width
                    size
                    // height
                    url
                    isLandscape
                    // exif {
                    //     longitude
                    //     eastOrWestLongitude
                    //     latitude
                    //     northOrSouthLatitude
                    //     altitude
                    //     altitudeRef
                    //     cameraBrand
                    //     cameraModel
                    //     __typename
                    // }
                    __typename
                }
                // thumbnailImage {
                //     width
                //     size
                //     height
                //     url
                //     isLandscape
                //     __typename
                // }
                originalImage {
                    //width
                    size
                    //height
                    url
                    isLandscape
                    __typename
                }
                // livePhoto {
                //     width
                //     size
                //     height
                //     url
                //     isLandscape
                //     __typename
                // }
                albumId
                description
                userTags
                clarifaiTags
                uploadDate
                originalFilename
                isMobileUpload
                albumName
                attributes
                // deletionState {
                //     deletedAt
                //     __typename
                // }
                __typename
            }',
        ];
        // remove from query every line that starts with //
        $data['query'] = preg_replace('/^ *\/\/.*$/m', '', $data['query']);
        $js = 'xhr= new XMLHttpRequest();
        xhr.open("POST", "https://app.photobucket.com/api/graphql/v2", false);
        xhr.setRequestHeader("Content-Type", "application/json");
        xhr.setRequestHeader("authorization", ' . json_encode($authToken, JSON_THROW_ON_ERROR) . ');
        xhr.send(' . json_encode(json_encode($data, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR) . ');
        xhr.responseText;';
        $ret = $page->evaluate($js)->getReturnValue(
            30000 // on a folder with >=2000 images, it takes a long time to get the response :(
        );
        $ret = json_decode($ret, true, 512, JSON_THROW_ON_ERROR);
        if (false) {
            $ret = array(
                'data' =>
                array(
                    'getAlbumImagesV2' =>
                    array(
                        'scrollPointer' => '340bc447-f5f3-40a7-991a-cd2d1b387ff4_2012-03-05T20:33:58_8a310850-9762-423c-9a65-ac289354e9a7_706bbe20-ad5c-402f-964b-b51d8b714c40',
                        'items' =>
                        array(
                            0 =>
                            array(
                                'id' => '0742d4d0-7553-4242-bef0-5a46bf90a0c3',
                                'title' => '',
                                'dateTaken' => NULL,
                                'status' =>
                                array(),
                                'image' =>
                                array(
                                    'size' => 45201,
                                    'url' => 'https://hosting.photobucket.com/albums/m614/4x4norway/ForumPhotos/FORUMFOLDER1/P1010225.jpg',
                                    'isLandscape' => NULL,
                                    '__typename' => 'ImageFile',
                                ),
                                'originalImage' =>
                                array(
                                    'size' => 45201,
                                    'url' => 'https://hosting.photobucket.com/albums/m614/4x4norway/ForumPhotos/FORUMFOLDER1/P1010225.jpg',
                                    'isLandscape' => true,
                                    '__typename' => 'ImageFile',
                                ),
                                'albumId' => '706bbe20-ad5c-402f-964b-b51d8b714c40',
                                'description' => '',
                                'userTags' =>
                                array(),
                                'clarifaiTags' =>
                                array(),
                                'uploadDate' => '2012-03-05T20:33:42.000',
                                'originalFilename' => 'P1010225.jpg',
                                'isMobileUpload' => false,
                                'albumName' => 'FORUMFOLDER1',
                                'attributes' => NULL,
                                '__typename' => 'Image',
                            ),
                            1 =>
                            array(
                                'id' => 'a1d4a567-243e-4528-bac8-353157dd5d39',
                                'title' => '',
                                'dateTaken' => NULL,
                                'status' =>
                                array(),
                                'image' =>
                                array(
                                    'size' => 185198,
                                    'url' => 'https://hosting.photobucket.com/albums/m614/4x4norway/ForumPhotos/FORUMFOLDER1/Bilde084.jpg?width=1920&height=1080&fit=bounds',
                                    'isLandscape' => NULL,
                                    '__typename' => 'ImageFile',
                                ),
                                'originalImage' =>
                                array(
                                    'size' => 185198,
                                    'url' => 'https://hosting.photobucket.com/albums/m614/4x4norway/ForumPhotos/FORUMFOLDER1/Bilde084.jpg',
                                    'isLandscape' => true,
                                    '__typename' => 'ImageFile',
                                ),
                                'albumId' => '706bbe20-ad5c-402f-964b-b51d8b714c40',
                                'description' => '',
                                'userTags' =>
                                array(),
                                'clarifaiTags' =>
                                array(),
                                'uploadDate' => '2012-03-05T20:33:47.000',
                                'originalFilename' => 'Bilde084.jpg',
                                'isMobileUpload' => false,
                                'albumName' => 'FORUMFOLDER1',
                                'attributes' => NULL,
                                '__typename' => 'Image',
                            ),
                            2 =>
                            array(
                                'id' => '06e8c6dc-708e-4a2b-93ac-fb658ff451c1',
                                'title' => '',
                                'dateTaken' => NULL,
                                'status' =>
                                array(),
                                'image' =>
                                array(
                                    'size' => 49503,
                                    'url' => 'https://hosting.photobucket.com/albums/m614/4x4norway/ForumPhotos/FORUMFOLDER1/P1010228.jpg',
                                    'isLandscape' => NULL,
                                    '__typename' => 'ImageFile',
                                ),
                                'originalImage' =>
                                array(
                                    'size' => 49503,
                                    'url' => 'https://hosting.photobucket.com/albums/m614/4x4norway/ForumPhotos/FORUMFOLDER1/P1010228.jpg',
                                    'isLandscape' => true,
                                    '__typename' => 'ImageFile',
                                ),
                                'albumId' => '706bbe20-ad5c-402f-964b-b51d8b714c40',
                                'description' => '',
                                'userTags' =>
                                array(),
                                'clarifaiTags' =>
                                array(),
                                'uploadDate' => '2012-03-05T20:33:52.000',
                                'originalFilename' => 'P1010228.jpg',
                                'isMobileUpload' => false,
                                'albumName' => 'FORUMFOLDER1',
                                'attributes' => NULL,
                                '__typename' => 'Image',
                            ),
                        ),
                        '__typename' => 'ImageScrollPointer',
                    ),
                ),
            );
        }
        var_dump($ret['data']['getAlbumImagesV2']['items']);
        $imagesSimplified = array();
        foreach ($ret['data']['getAlbumImagesV2']['items'] as $image) {
            $imagesSimplified[] = array(
                'id' => $image['id'],
                'title' => $image['title'],
                'originalFilename' => $image['originalFilename'],
                'originalImageURL' => $image['originalImage']['url'],
                'uploadDate' => $image['uploadDate'],
                'path' => $folder_data['path'] . '/' . $image['originalFilename'],
            );
        }
        if (false && !empty($imagesSimplified)) {
            var_export($imagesSimplified);
            dd($imagesSimplified);
        }
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_USERAGENT => 'photobucket-account-downloader',
            CURLOPT_AUTOREFERER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING => '',
            CURLOPT_RETURNTRANSFER => true,
        ));
        foreach ($imagesSimplified as $imageSimplified) {
            echo "Downloading {$imageSimplified['path']}\n";
            $fullPath = __DIR__  . '/downloads/' . $imageSimplified['path'];
            $dir = dirname($fullPath);
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0777, true)) {
                    throw new \Exception("Failed to create directory $dir");
                }
            }
            if (file_exists($fullPath)) {
                echo "File already exists, skipping\n";
                continue;
            }
            curl_setopt($ch, CURLOPT_URL, $imageSimplified['originalImageURL']);
            $data = curl_exec($ch);
            if (!is_string($data) || curl_errno($ch) !== CURLE_OK) {
                throw new \Exception("Curl error while downloading '{$imageSimplified['originalImageURL']}': " . curl_errno($ch) . ": " . curl_error($ch));
            }
            if (strlen($data) !== file_put_contents($fullPath, $data, LOCK_EX)) {
                unlink($fullPath);
                throw new \Exception("Failed to write file $fullPath");
            }
            $uploadDate = strtotime($imageSimplified['uploadDate']);
            if ($uploadDate === false) {
                throw new \Exception("Failed to parse upload date '{$imageSimplified['uploadDate']}'");
            }
            if (!touch($fullPath, $uploadDate)) {
                throw new \Exception("Failed to set upload date for $fullPath");
            }
        }
        curl_close($ch);
    }
}

$photobucket = new Photobucket();
$photobucket->login($username, $password);
echo "Logged in\n";
$photobucket->downloadEverything();
die("TODO: fix folders again :( photobucket made major changes to folder APIs and i don't have time/motivation to fix it :(");
$folders = $photobucket->getFolders();
foreach ($folders as $folders) {
    var_export($folders);
    $photobucket->downloadFolder($folders);
}
// Usage: EvalLoop([...get_defined_vars(), "xthis" => $this]);
function EvalLoop($vars)
{
    // Import variables into local scope.
    extract($vars);

    // Print invocation details.
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
    if (isset($trace[1])) {
        $caller = $trace[1];
        $file = isset($caller['file']) ? $caller['file'] : 'Unknown file';
        $line = isset($caller['line']) ? $caller['line'] : 'Unknown line';
        echo "EvalLoop invoked from: File: {$file}, Line: {$line}" . PHP_EOL;
    } else {
        echo "EvalLoop invoked directly (no caller information available)." . PHP_EOL;
    }

    // Print the available variables.
    echo "Available Variables:" . PHP_EOL;
    foreach ($vars as $key => $value) {
        $type = is_object($value) ? get_class($value) : gettype($value);
        echo " - {$key}: {$type}" . PHP_EOL;
    }
    echo str_repeat('-', 40) . PHP_EOL;
    echo "Type 'exit' to quit the evaluation loop." . PHP_EOL;

    $code = "";
    while (true) {
        // Read input lines until an empty line is encountered.
        while (true) {
            $tmp = readline('> ');
            if ($tmp === false) {
                // Break out if readline returns false (for example, on CTRL+D).
                break 2;
            }
            $tmp = trim($tmp);
            readline_add_history($tmp);

            // If an empty line, stop reading further for this statement.
            if ($tmp === '') {
                break;
            }
            $code .= $tmp . "\n";

            // If this line ends with a semicolon, assume end of statement.
            if (str_ends_with($tmp, ';')) {
                break;
            }
        }

        $code = trim($code);
        if ($code === 'exit') {
            echo "Exiting EvalLoop." . PHP_EOL;
            break;
        }

        echo "Evaluating:" . PHP_EOL . $code . PHP_EOL;
        try {
            // Evaluate the code and capture the result.
            $result = eval($code);
            echo "Result: ";
            var_dump($result);
        } catch (Throwable $ex) {
            echo "Error during evaluation:" . PHP_EOL;
            var_dump($ex);
        }

        // Reset code for the next iteration.
        $code = "";
        echo str_repeat('-', 40) . PHP_EOL;
    }
}
