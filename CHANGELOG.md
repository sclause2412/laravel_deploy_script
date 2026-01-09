# Changelog

## [v2.0.1]

### 🐞 Bugfix

- Fix issue if posix functions are not installed (in deploy file)
- Fix issue with home path for Windows

---

## [v2.0.1]

### 🐞 Bugfix

- Fix issue if posix functions are not installed

---

## [v2.0.0]

### ✨ New

- Support setups where rights are limited to current folder
- Support setups where app need to run in subfolder
- Add more checks

### ♻️ Change

- Improve deploy.php folder detection
- Improve writing of .env settings

---


## [v1.1.0]

### ✨ New

- Check prerequisites

### 🐞 Bugfix

- Layout issues with buttons
- Support windows environment

---

## [v1.0.0]

**First release**

### ✨ New

- Automatic SSH keypair generation
- Checkout of public and private repository
- Installation of dependencies for your project (Composer & npm)
- Live creation / modification of .env file
- Run migrations
- Run npm build
- Create storage link
- Optimize project (artisan optimize)
- Creation of deploy file
- Instructions for setting up GitHub
