# SVG Support

[![Version](https://img.shields.io/wordpress/plugin/v/svg-support?label=version)](https://wordpress.org/plugins/svg-support/)
[![Requires PHP](https://img.shields.io/wordpress/plugin/required-php/svg-support)](https://wordpress.org/plugins/svg-support/)
[![Requires WordPress](https://img.shields.io/wordpress/plugin/wp-version/svg-support?label=requires%20WordPress)](https://wordpress.org/plugins/svg-support/)
[![Tested WordPress](https://img.shields.io/wordpress/plugin/tested/svg-support?label=WordPress%20tested)](https://wordpress.org/plugins/svg-support/)
[![License](https://img.shields.io/github/license/benbodhi/svg-support)](https://www.gnu.org/licenses/gpl-2.0.html)
[![Active Installs](https://img.shields.io/wordpress/plugin/installs/svg-support?label=active%20installs)](https://wordpress.org/plugins/svg-support/advanced/)
[![Rating](https://img.shields.io/wordpress/plugin/stars/svg-support?label=rating)](https://wordpress.org/support/plugin/svg-support/reviews/)
[![Playground Demo](https://img.shields.io/badge/Playground-Demo-2271b1?logo=wordpress&logoColor=white)](https://playground.wordpress.net/?plugin=svg-support)

A powerful WordPress plugin that enables SVG uploads and provides advanced features for working with SVG files in WordPress.

> **Development Repository**
>
> This GitHub repository is the development workspace for SVG Support and may be ahead of the released version. For production sites, install the official release from the [WordPress.org plugin repository](https://wordpress.org/plugins/svg-support/).
>
> Releases are published to WordPress.org via SVN; this repository is used for development and issue tracking.

## Description

SVG Support allows you to securely upload SVG files to your WordPress Media Library and use them like any other image, with additional features for inline rendering, styling, and animation.

### Key Features

- **SVG Upload Support**: Easily upload SVG files to your media library
- **Automatic Sanitization**: All SVG uploads are sanitized by default for security
- **Minification Options**: Reduce SVG file sizes with optional minification
- **Inline Rendering**: Render SVG code inline by adding the `"style-svg"` class
- **Role-Based Control**: Restrict SVG upload capabilities to specific user roles
- **Custom Target Class**: Define your own CSS class for targeting SVGs
- **Featured Image Support**: Special handling for SVG files as featured images
- **Advanced Mode**: Toggle advanced features for more control

## Installation

1. Install through the WordPress plugin repository or upload to your `/wp-plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to "Settings > SVG Support" to configure the plugin

## Basic Usage

Once activated, you can upload SVG files to your media library like any other image file.

### Inline SVG Rendering

To render an SVG inline (enabling CSS/JS targeting of internal elements):

```
<img class="style-svg" alt="alt-text" src="image-source.svg" />
```

Or with a custom class:

```
<img class="your-custom-class" alt="alt-text" src="image-source.svg" />
```

## Security

SVG Support takes security seriously and provides several features to ensure safe SVG handling:

- Sanitization by default (since v2.5.8)
- Role-based upload restrictions
- Optional sanitization bypass for trusted users
- Secure file handling through WordPress APIs

## Development

This is the development repository for SVG Support. The official release version is maintained on WordPress.org's SVN repository.

- [Plugin Page on WordPress.org](https://wordpress.org/plugins/svg-support/)
- [SVN Repository](https://plugins.svn.wordpress.org/svg-support/)

### Quick Test

Want to try it out? Spin up a test site instantly:
[Click here to create a test site with SVG Support pre-installed](https://tastewp.com/new?pre-installed-plugin-slug=svg-support&redirect=options-general.php%3Fpage%3Dsvg-support&ni=true)

## Contributing

Contributions are welcome! Feel free to:

- Submit bug reports or feature requests through the [issue tracker](https://github.com/benbodhi/svg-support/issues)
- Create pull requests for bug fixes or new features
- Help with translations through [WordPress.org's translation platform](https://translate.wordpress.org/projects/wp-plugins/svg-support)

## Support

- For general support, please use the [WordPress.org support forums](https://wordpress.org/support/plugin/svg-support/)
- For bug reports and feature requests, use the GitHub issues

## License

This plugin is licensed under the GPL v2 or later.

## Author

Created and maintained by [Benbodhi](https://benbodhi.com)

### Follow SVG Support

- [@SVGSupport on Twitter](https://twitter.com/svgsupport)
- [@benbodhi on Twitter](https://twitter.com/benbodhi)
- [@benbodhi on Farcaster](https://farcaster.xyz/benbodhi)

## Support the Development

If you find this plugin useful, please consider:
- [Rating it on WordPress.org](https://wordpress.org/support/plugin/svg-support/reviews/#new-post)
- [Making a donation](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=Z9R7JERS82EQQ)