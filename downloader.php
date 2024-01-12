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
    function __construct()
    {
        $factory = new \HeadlessChromium\BrowserFactory();
        $browser = $factory->createBrowser(
            [
                'headless' => false,
                'noSandbox' => true,
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
            \HeadlessChromium\Page::LOAD
        );
        $page->mouse()->find("#username")->click();
        $page->keyboard()->typeText($username);
        $page->mouse()->find("#password")->click();
        $page->keyboard()->typeText($password);
        $page->evaluate('document.querySelector("button[type=submit]").click()')->getReturnValue();
        try {
            $page->waitForReload(\HeadlessChromium\Page::DOM_CONTENT_LOADED, 10000);
        } catch (\Exception $e) {
            // ignore
        }
        // <div class="MuiAlert-message css-1xsto0d"><div class="MuiTypography-root MuiTypography-body1 MuiTypography-gutterBottom MuiAlertTitle-root jss20 jss21 css-vhidae">Error logging in</div>Invalid email/username or password</div>
        $error = $page->evaluate('document.querySelector(".MuiAlert-message")?.textContent')->getReturnValue();
        if ($error) {
            throw new Exception("Error logging in: $error");
        }
        // https://app.photobucket.com/u/4x4norway?planId=storage-monthly&login=true
        $uri = $page->evaluate('document.location.href')->getReturnValue();
        preg_match('#/u/([^?]+)#', $uri, $matches);
        //$this->internal_username = urldecode($matches[1]);
        $this->internal_username = $matches[1];
        echo "internal_username: {$this->internal_username}\n";
    }

    function getFolders(): array
    {
        $ret = [];
        $page = &$this->page;
        // document.cookie 'cwr_u=; _gid=GA1.2.1666773935.1705050541; _fbp=fb.1.1705050541028.700942932; _tt_enable_cookie=1; _ttp=KsD1j70yQE35rPrmL8O1Mz0Zg1-; _pin_unauth=dWlkPVpEVTBPRFl3WldZdFpUVmhaaTAwTVRkaUxXRXlZbUV0TXpReFpEUmtNMkkyT1dObA; __hstc=35533630.673d82828e43783dc135d49f531c15a7.1705050543222.1705050543222.1705050543222.1; hubspotutk=673d82828e43783dc135d49f531c15a7; __hssrc=1; _gcl_au=1.1.714453139.1705050541.1346485070.1705050543.1705050543; app_auth=eyJhbGciOiJSUzI1NiIsImtpZCI6IjdjZjdmODcyNzA5MWU0Yzc3YWE5OTVkYjYwNzQzYjdkZDJiYjcwYjUiLCJ0eXAiOiJKV1QifQ.eyJuYW1lIjoiSGFucyBIZW5yaWsgQmVyZ2FuIiwicGljdHVyZSI6Imh0dHBzOi8vbGgzLmdvb2dsZXVzZXJjb250ZW50LmNvbS9hL0FHTm15eFlSX1drd1MtbV9WUjZLQUlSRWJUeWZUT2E5NGh0QVlGMGVORjZIPXM5Ni1jIiwiaXNHcm91cFVzZXIiOnRydWUsInBhc3N3b3JkRXhwaXJlZCI6ZmFsc2UsImlzcyI6Imh0dHBzOi8vc2VjdXJldG9rZW4uZ29vZ2xlLmNvbS9waG90b2J1Y2tldC1tb2JpbGUtYXBwcyIsImF1ZCI6InBob3RvYnVja2V0LW1vYmlsZS1hcHBzIiwiYXV0aF90aW1lIjoxNzA1MDUwNTQzLCJ1c2VyX2lkIjoiNHg0bm9yd2F5Iiwic3ViIjoiNHg0bm9yd2F5IiwiaWF0IjoxNzA1MDUwNTQzLCJleHAiOjE3MDUwNTQxNDMsImVtYWlsIjoiZGl2aW5pdHk3NkBnbWFpbC5jb20iLCJlbWFpbF92ZXJpZmllZCI6dHJ1ZSwiZmlyZWJhc2UiOnsiaWRlbnRpdGllcyI6eyJlbWFpbCI6WyJkaXZpbml0eTc2QGdtYWlsLmNvbSJdfSwic2lnbl9pbl9wcm92aWRlciI6ImN1c3RvbSJ9fQ.ffaGZ_cyh10xLVGo1KOD5NvWxwGpIRIS8521G-iKYX5kwF8kvnXGl_TJXI63sPamHkVODHGCmoVl143N7u-rOILfEMOuJsLWWCkc0TUp-KdnwffX__seGh4QuKg4gWN7jjU7tmRXIVGbPkk_0jaeciulM2-YtzlUR0AvDZ_O7LrPTUueaCJxTpiQd1q5fgyyvxqxQPysX9QkANkcvRNLBFO7D0DCmgg2aJdb-c5QPzgWTdao1kp9TMmRUh8EbzR43pS8ZCZR_KQXNs52MnI8o6e-9omyrvIgjKelznlx9IenZIR0jQd4US6TCtIkSnI8hizpm2vdEloCLTCZIOMTEg; _uetsid=39b43650b12a11eebf3d1b2e4684110f; _uetvid=39b42640b12a11eea19de38ab0318a25; _ga=GA1.2.1556083939.1705050541; _ga_Y2Z30LCFMB=GS1.1.1705050540.1.1.1705050546.54.0.0; __hssc=35533630.3.1705050543222'
        // /app_auth=([^;]+)/.exec(document.cookie)[1]
        // https://app.photobucket.com/u/4x4norway/albums
        $page->navigate("https://app.photobucket.com/u/{$this->internal_username}/albums")->waitForNavigation(
            \HeadlessChromium\Page::DOM_CONTENT_LOADED
        );
        $authToken = $page->evaluate('/app_auth=([^;]+)/.exec(document.cookie)[1]')->getReturnValue();
        $postData = array(
            'operationName' => 'AlbumsReadAll',
            'variables' => array(
                'sortBy' => array(
                    'desc' => false,
                ),
            ),
            'query' => 'query AlbumsReadAll($sortBy: Sorter!) {
                albumsReadAll(sortBy: $sortBy) {
                    ...AlbumFragment
                    __typename
                }
            }
        
            fragment AlbumFragment on AlbumV2 {
                id
                title
                privacyMode
                parentAlbumId
                description
                owner
                counters {
                    imageCountIncludeSubAlbums
                    imageCount
                    nestedAlbumsCount
                    __typename
                }
                __typename
            }',
        );
        $js = 'xhr= new XMLHttpRequest();
        xhr.open("POST", "https://app.photobucket.com/api/graphql/v2", false);
        xhr.setRequestHeader("Content-Type", "application/json");
        xhr.setRequestHeader("authorization", ' . json_encode($authToken, JSON_THROW_ON_ERROR) . ');
        xhr.send(' . json_encode(json_encode($postData, JSON_THROW_ON_ERROR), JSON_THROW_ON_ERROR) . ');
        xhr.responseText;';
        $ret = $page->evaluate($js)->getReturnValue();
        $ret = json_decode($ret, true, 512, JSON_THROW_ON_ERROR);
        if (false) {
            $ret = array(
                'data' =>
                array(
                    'albumsReadAll' =>
                    array(
                        0 =>
                        array(
                            'id' => 'b650a0d5-9ea6-41ed-a464-8868011b50ff',
                            'title' => 'ForumPhotos',
                            'privacyMode' => 'PUBLIC',
                            'parentAlbumId' => '197268f5-6144-4870-a9ba-f920959db4d1',
                            'description' => NULL,
                            'owner' => '4x4norway',
                            'counters' =>
                            array(
                                'imageCountIncludeSubAlbums' => 6602,
                                'imageCount' => 0,
                                'nestedAlbumsCount' => 3,
                                '__typename' => 'AlbumCounters',
                            ),
                            '__typename' => 'AlbumV2',
                        ),
                        1 =>
                        array(
                            'id' => '16d33369-89af-437b-a38d-c13111b39a1b',
                            'title' => 'Vikingtreff 2012',
                            'privacyMode' => 'PUBLIC',
                            'parentAlbumId' => '197268f5-6144-4870-a9ba-f920959db4d1',
                            'description' => NULL,
                            'owner' => '4x4norway',
                            'counters' =>
                            array(
                                'imageCountIncludeSubAlbums' => 211,
                                'imageCount' => 211,
                                'nestedAlbumsCount' => 0,
                                '__typename' => 'AlbumCounters',
                            ),
                            '__typename' => 'AlbumV2',
                        ),
                        14 =>
                        array(
                            'id' => '825b0d58-79e8-4b43-870c-48c4fb4f2368',
                            'title' => 'Mobile Uploads',
                            'privacyMode' => 'PRIVATE',
                            'parentAlbumId' => '197268f5-6144-4870-a9ba-f920959db4d1',
                            'description' => NULL,
                            'owner' => '4x4norway',
                            'counters' =>
                            array(
                                'imageCountIncludeSubAlbums' => 0,
                                'imageCount' => 0,
                                'nestedAlbumsCount' => 0,
                                '__typename' => 'AlbumCounters',
                            ),
                            '__typename' => 'AlbumV2',
                        ),
                        15 =>
                        array(
                            'id' => '197268f5-6144-4870-a9ba-f920959db4d1',
                            'title' => 'My Bucket',
                            'privacyMode' => 'PUBLIC',
                            'parentAlbumId' => NULL,
                            'description' => NULL,
                            'owner' => '4x4norway',
                            'counters' =>
                            array(
                                'imageCountIncludeSubAlbums' => 9830,
                                'imageCount' => 23,
                                'nestedAlbumsCount' => 18,
                                '__typename' => 'AlbumCounters',
                            ),
                            '__typename' => 'AlbumV2',
                        ),
                        16 =>
                        array(
                            'id' => '706bbe20-ad5c-402f-964b-b51d8b714c40',
                            'title' => 'FORUMFOLDER1',
                            'privacyMode' => 'PUBLIC',
                            'parentAlbumId' => 'b650a0d5-9ea6-41ed-a464-8868011b50ff',
                            'description' => NULL,
                            'owner' => '4x4norway',
                            'counters' =>
                            array(
                                'imageCountIncludeSubAlbums' => 2156,
                                'imageCount' => 2156,
                                'nestedAlbumsCount' => 0,
                                '__typename' => 'AlbumCounters',
                            ),
                            '__typename' => 'AlbumV2',
                        ),
                        17 =>
                        array(
                            'id' => '4eb3753d-6d35-4723-87bc-2385edf45fe0',
                            'title' => 'FORUMFOLDER2',
                            'privacyMode' => 'PUBLIC',
                            'parentAlbumId' => 'b650a0d5-9ea6-41ed-a464-8868011b50ff',
                            'description' => NULL,
                            'owner' => '4x4norway',
                            'counters' =>
                            array(
                                'imageCountIncludeSubAlbums' => 2088,
                                'imageCount' => 2088,
                                'nestedAlbumsCount' => 0,
                                '__typename' => 'AlbumCounters',
                            ),
                            '__typename' => 'AlbumV2',
                        ),
                        18 =>
                        array(
                            'id' => '22fe5b2c-464e-4417-b6e2-a3a99b067799',
                            'title' => 'FORUMFOLDER3',
                            'privacyMode' => 'PUBLIC',
                            'parentAlbumId' => 'b650a0d5-9ea6-41ed-a464-8868011b50ff',
                            'description' => NULL,
                            'owner' => '4x4norway',
                            'counters' =>
                            array(
                                'imageCountIncludeSubAlbums' => 2358,
                                'imageCount' => 2358,
                                'nestedAlbumsCount' => 0,
                                '__typename' => 'AlbumCounters',
                            ),
                            '__typename' => 'AlbumV2',
                        ),
                    ),
                ),
            );
        }
        $folders = (function () use ($ret): array {
            $foldersSimplified = array();
            foreach ($ret['data']['albumsReadAll'] as $folder) {
                $foldersSimplified[] = array(
                    'id' => $folder['id'],
                    'title' => $folder['title'],
                    'privacyMode' => $folder['privacyMode'],
                    'parentAlbumId' => $folder['parentAlbumId'] ?? null,
                    'description' => $folder['description'],
                    'owner' => $folder['owner'],
                    'counters' => $folder['counters'],
                );
            }
            foreach ($foldersSimplified as &$folderSimplified) {
                $path = '';
                if (empty($folderSimplified['parentAlbumId'])) {
                    $path = '/' . $folderSimplified['title'];
                } else {
                    $path = '/' . $folderSimplified['title'];
                    $parent = $folderSimplified['parentAlbumId'];
                    while (!empty($parent)) {
                        $parentFolder = array_filter($foldersSimplified, function ($folder) use ($parent) {
                            return $folder['id'] == $parent;
                        });
                        $parentFolder = array_shift($parentFolder);
                        $path = '/' . $parentFolder['title'] . $path;
                        $parent = $parentFolder['parentAlbumId'];
                    }
                }
                $folderSimplified['path'] = $path;
            }
            unset($folderSimplified);
            return $foldersSimplified;
        })();
        return $folders;
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
        }
        curl_close($ch);
    }
}

$photobucket = new Photobucket();
$photobucket->login($username, $password);
echo "Logged in\n";
$folders = $photobucket->getFolders();
foreach ($folders as $folders) {
    var_export($folders);
    $photobucket->downloadFolder($folders);
}
