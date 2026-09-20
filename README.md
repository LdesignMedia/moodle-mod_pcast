# Pcast — Podcasting module for Moodle

[![ci](https://github.com/LdesignMedia/moodle-mod_pcast/actions/workflows/ci.yml/badge.svg)](https://github.com/LdesignMedia/moodle-mod_pcast/actions/workflows/ci.yml)

Pcast is a Moodle activity module for publishing and subscribing to podcasts inside a
course. Teachers and (optionally) students can post audio or video episodes, which are
moderated, rated, commented on, tagged, and published as a standards-compliant RSS feed
that learners can subscribe to in any podcast client, including iTunes/Apple Podcasts.

## Requirements

- Moodle 5.0 or later (`$plugin->requires = 2025041400`)
- PHP 8.2 or later

## Installation

1. Copy the plugin into `mod/pcast/` in your Moodle site (or install the ZIP via
   *Site administration → Plugins → Install plugins*).
2. Log in as an administrator and follow the upgrade prompt to complete installation.

See the Moodle documentation for details:
<https://docs.moodle.org/en/Installing_plugins>.

## Features

- Post podcast episodes as audio or video; episodes can be moderated before publication.
- Full RSS support, including podcast channel art (per the RSS specification).
- Easy subscribe link for iTunes/Apple Podcasts users.
- iTunes tags, keywords, and categories per episode.
- Episodes support commenting and rating.
- Episodes are tagged using the Moodle tagging API and are fully searchable via global search.
- Activity completion, including display on the *My overview* / *Timeline* block.
- Teachers can restrict uploads to specific file types.
- All activity is logged through the Moodle events API.
- Implements the Moodle Privacy API (GDPR).

## Bug reports and feature requests

Please report issues for this Moodle 5.x version to the maintained repository:
<https://github.com/LdesignMedia/moodle-mod_pcast/issues>.

## Credits

Pcast was originally created by **Stephen Bourget** (most of the coding and design) with
**Jillaine Beeckman** (QA testing and bug-fixing for the initial release), and was
developed for **Goffstown School District** (Goffstown, NH, USA). Many ideas and code were
adapted from other Moodle modules and from Moodle core. The original project is at
<https://github.com/sbourget/moodle-mod_pcast>.

This Moodle 5.x–compatible version is maintained by **Ldesign Media**
(<https://ldesignmedia.nl>), which updated the plugin for current Moodle and PHP releases
while preserving the original authors' work and copyright.

### Third-party libraries

- [getID3](https://www.getid3.org/) v1.9.24 by James Heinrich, used to read media metadata,
  bundled under the GPL v3. See `thirdpartylibs.xml` and `lib/getid3/`.

## License

Copyright (C) 2010–2025 Stephen Bourget, Jillaine Beeckman, and others.
Maintained for Moodle 5.x by Ldesign Media.

This program is free software: you can redistribute it and/or modify it under the terms of
the GNU General Public License as published by the Free Software Foundation, either version
3 of the License, or (at your option) any later version.

This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY;
without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
See the GNU General Public License for more details: <https://www.gnu.org/licenses/gpl-3.0.html>.
