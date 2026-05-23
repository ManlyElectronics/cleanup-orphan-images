# Cleanup Orphan Images

Published at https://wordpress.org/plugins/cleanup-orphan-images/

WordPress plugin Finds and deletes orphan media files from the uploads directory that are not registered in WordPress DB.
This does not include Unattached media, which can be easily found and removed in the standard Media Library.
These orphan files may have been left behind from:

* Failed or interrupted uploads
* Plugin operations or migrations
* FTP/SFTP uploads not registered in WordPress
* Database restores or imports

= Features =

* Scans uploads directory for orphan media files
* Supports images: JPG, JPEG, PNG, GIF, WebP, BMP, TIFF, SVG, ICO
* Supports documents: PDF, DOC, DOCX, XLS, XLSX, PPT, PPTX, ODT, ODS, ODP, TXT, RTF, CSV
* Supports audio: MP3, WAV, OGG, FLAC, AAC, M4A, WMA
* Supports video: MP4, MOV, AVI, WMV, MKV, WebM, FLV, M4V, MPEG, MPG
* Supports archives: ZIP, RAR, 7Z, TAR, GZ
* Safe manual selection before deletion
* Warns when orphan count exceeds server's max_input_vars limit (typically 1000)
* Optimized O(1) performance with hash-based detection

= Note =

This plugin does NOT manage "unattached" images in the Media Library. WordPress already provides built-in filtering for unattached media. This plugin focuses specifically on finding physical files that WordPress doesn't know about at all.

== Installation ==

1. Upload the `cleanup-orphan-images` folder to `/wp-content/plugins/`
2. Activate through the 'Plugins' menu
3. Go to Media > Cleanup Orphan Images

== Frequently Asked Questions ==

= What are orphan files? =

Orphan files are physical media files in your uploads folder that exist on the server but are not registered in the WordPress database.

= Is it safe to delete orphan files? =

Review the list before deletion. Some files may still be used by themes or plugins that reference them directly by URL. Always back up your uploads folder first.

= Can I recover deleted files? =

No, deletion is permanent. Back up your site first.

= Interaction with other plugins =

Some hosting providers and image optimisation plugins (such as Imagify or ShortPixel) auto-generate `.webp` versions of images using Nginx/Apache rewrite rules and store them in the uploads folder without registering them in WordPress.

Server-side rewrite rules is not part of the official WordPress media management specification and is not endorsed by WordPress. This plugin follows the WordPress standard — if a file is not registered in the WordPress database, it is an orphan. Any files created outside of that standard by third-party tools or hosting configurations are beyond the scope and responsibility of this plugin.

If you see unexpected files in the scan results, do not select them for deletion unless you are absolutely sure they are not used.

For image optimisation, the workaround is to disable server-side rewriting plugins and switch to a WordPress-native WebP converter (such as [Manly WebP Converter](https://wordpress.org/plugins/manly-webp-converter/)) which significantly reduces file sizes and registers converted files properly in the database — after which those files will never appear as orphans.


== Changelog ==

= 1.8.0 =
* Simplified plugin to focus on orphan files only
* Removed unattached images feature (use WordPress built-in Media Library filter instead)
* Expanded file format support: images, documents, audio, video, archives
* Cleaner, more focused user interface

= 1.7.2 =
* Minor GUI fix

= 1.7.1 =
* Removed broken automated batch deletion button

= 1.7.0 =
* Full PHPCS/WPCS coding standards compliance
* Performance optimization for orphan file scanning
* Security improvements with capability checks

= 1.6.0 =
* WordPress.org compliance improvements
* Enhanced security and caching

= 1.5.0 =
* Added orphan files detection
* Batch processing with progress tracking

= 1.0.0 =
* Initial release
