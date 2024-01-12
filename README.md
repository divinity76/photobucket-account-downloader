# photobucket-account-downloader
download your entire photobucket account.
- because the official "download folder" approach gives 500 Server Error on large folders, and the official API is down (it's impossible to create API keys :( )

# requirements
- git
- php-cli >= 8  (7.4 might work, haven't tested, patches welcome)
- composer
- Chromium or Google Chrome

# usage
```bash
git clone 'https://github.com/divinity76/photobucket-account-downloader.git' --depth 1;
cd photobucket-account-downloader;
echo 'login@email.com' > photobucket_username.txt;
echo 'password' > photobucket_password.txt;
composer install;
time php downloader.php;
```
then you should soon see
```bash
Downloading /My Bucket/Rena Sept 2014/th_Y2014M09D10_P9106280x.jpg
Downloading /My Bucket/Rena Sept 2014/Y2014M09D10_P9106280x.jpg
Downloading /My Bucket/Rena Sept 2014/th_Y2014M09D10_P9106279x.jpg
Downloading /My Bucket/Rena Sept 2014/Y2014M09D10_P9106279x.jpg
Downloading /My Bucket/Rena Sept 2014/Y2014M09D10_P9106286x.jpg
Downloading /My Bucket/Rena Sept 2014/th_Y2014M09D10_P9106286x.jpg
Downloading /My Bucket/Rena Sept 2014/th_Y2014M09D11_P9116289x.jpg
Downloading /My Bucket/Rena Sept 2014/Y2014M09D11_P9116289x.jpg
Downloading /My Bucket/Rena Sept 2014/Y2014M09D11_P9116290x.jpg
Downloading /My Bucket/Rena Sept 2014/th_Y2014M09D11_P9116290x.jpg
Downloading /My Bucket/Rena Sept 2014/th_Y2014M09D11_P9116291x.jpg
Downloading /My Bucket/Rena Sept 2014/th_Y2014M09D11_P9116292x.jpg
Downloading /My Bucket/Rena Sept 2014/Y2014M09D11_P9116291x.jpg
Downloading /My Bucket/Rena Sept 2014/Y2014M09D11_P9116292x.jpg
Downloading /My Bucket/Rena Sept 2014/Y2014M09D11_P9116288x.jpg
Downloading /My Bucket/Rena Sept 2014/th_Y2014M09D11_P9116288x.jpg
Downloading /My Bucket/Rena Sept 2014/Y2014M09D11_P9116302x.jpg
Downloading /My Bucket/Rena Sept 2014/th_Y2014M09D11_P9116302x.jpg
Downloading /My Bucket/Rena Sept 2014/Y2014M09D11_P9116297x.jpg
Downloading /My Bucket/Rena Sept 2014/th_Y2014M09D11_P9116297x.jpg
```
original filenames and folder structure should be intact, and it should look something like
![image](https://github.com/divinity76/photobucket-account-downloader/assets/1874996/215b9c79-ff37-4557-88ee-bff6b08c17ac)
