# Requirements
WordPress + WPML

# What problem does it solve?
WordPress mu-plugin that fixes a known WPML edge case where deleted media attachments leave orphaned physical files behind in wp-content/uploads/. Includes an admin diagnostic tool to audit and surface existing orphans.

# How to install
Just drop it as a `mu-plugin` or activate it as a regular plugin.

# How to use it
Access `/wp-admin/?check_orphan_uploads=1` to run the scan and find/delete the orphan files.
