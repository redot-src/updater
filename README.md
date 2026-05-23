# Redot Updater

🚨 **Experimental Package** - This package is currently in experimental status. Use with caution in production environments.

A Laravel package that provides seamless integration with [Redot.dev](https://redot.dev) dashboard scaffold. Keep your Redot-based Laravel project up to date with the latest scaffold improvements and features.

## About

Redot Updater is a command-line tool designed to help you maintain and update your Redot dashboard scaffold. It connects to the Redot platform to sync the latest updates, preview changes, and manage your project's evolution over time.

## Requirements

- PHP ^8.1
- Laravel ^10.0|^11.0|^12.0

## Installation

Install the package via Composer:

```bash
composer require redot/updater
```

The package will automatically register its service provider via Laravel's package auto-discovery.

## Usage

The package provides four main commands to manage your Redot dashboard:

### 1. Login to Redot

Authenticate with your Redot account and get your project token and slug:

```bash
php artisan redot:login
```

This command will prompt you for your credentials and store the necessary authentication tokens for subsequent operations.

### 2. Logout

Clear your stored authentication credentials:

```bash
php artisan redot:logout
```

### 3. Preview Changes

Generate a URL to preview the differences between your current project and the latest scaffold:

```bash
php artisan redot:diff
```

This command outputs a URL that you can visit on [Redot.dev](https://redot.dev) to review the changes before applying them.

### 4. Update Project

Update your project to the latest Redot scaffold:

```bash
php artisan redot:update
```

This command performs a **git-style 3-way merge**. It downloads two scaffold snapshots — your current commit (the "base") and the latest (the "incoming") — and merges each changed file against the version in your project using `git merge-file`. Files you have not touched update cleanly; files you have customized are merged with your changes preserved when possible.

**Atomic behavior**

By default, if any file conflicts during the merge, the command aborts and **no files in your project are modified**. It prints the conflicted paths and exits non-zero so you can decide how to proceed.

**Force mode**

To apply the merge anyway and have conflict markers written into the affected files (the same `<<<<<<<` / `=======` / `>>>>>>>` markers `git merge` produces), pass `--force`:

```bash
php artisan redot:update --force
```

In this mode, conflicted files are written into your project with the merge markers in place. Open each file, resolve the markers, and commit. **You do not need to re-run `redot:update` afterwards** — the merge is already applied.

**Commit Changes**

Even with the 3-way merge in place, it is still good practice to commit (or stash) your local changes before running the update command, so you can review the resulting diff and roll back if needed.

## Limitations

⚠️ **Requires `git`**: The update command shells out to `git merge-file` for the 3-way merge, so the `git` binary must be on your `PATH`. Your project itself does not need to be a git repository.

⚠️ **Binary files are not merged**: `git merge-file` only handles text. When a binary file changes upstream, the incoming version replaces your copy (logged as `binary`). Back up any binaries you have customized before updating.

## Contributing

This is an experimental package. Contributions are welcome, but please note that the API and functionality may change significantly in future versions.

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

## Disclaimer

This package is experimental and should be used with caution. Always backup your project before running update commands. The package maintainers are not responsible for any data loss or issues that may occur during the update process.
