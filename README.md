# Laravel automatic installation and deploy

This tool tries to install an automatic deployment solution for your GitHub repository with a Laravel app.

It is always annoying to me if I have a Laravel project which I want to deploy to a server which does not natively support automatic Laravel deployment. In my case I was using an own VPS with Cloudpanel installed. Everytime when I install or update a Laravel application I have either to generate all files on a local computer and then update the server with FTP, or I have to login to the server with SSH and do it there manually. Therefore, I created this little tool to do this for me automatically.

Currently this tool supports installation only for things I need, so maybe some features are currently missing (but they may come in future).

## Usage

- Download the latest version from the [Releases](https://github.com/sclause2412/laravel_deploy_script/releases) page.
- Create a new website on your webserver using the folder `public` as root for your website (this is the default folder for Laravel).
- Upload the [install.php](install.php) file to the `public` folder.
- Run the installation on any browser (e.g. https://example.com/install.php)

The script will guide you through the installation.

### Limited setup

In case your webserver is limited, e.g.

- webroot folder has different name (e.g. htdocs)
- you are not allowed to put files outside the webroot folder
- you cannot use subdomains but require to run Laravel app in a subfolder (e.g. https://example.com/laravelapp)

you may still use this script and it will generate / modify some files to make the setup work as good as possible.

Just place the install file in the webroot folder or any subfolder you want and call it in the browser.

### Requirements

To use the automatic installation and deployment your server must have installed:

- PHP
- npm (Node)
- Composer
- GIT
- SSH client (if using a private repository)

## Changelog

See the latest changes in the [Changelog file](CHANGELOG.md).

## Contribute

- All development is based on the *main* branch
- If you find an issue please report it by creating an issue here
- Before creating a pull request please create an issue

**I'm very thankful for everyone who supports this little project.**

## License

This software is licensed under GPLv3, see [license file](LICENSE).
