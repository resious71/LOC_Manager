# cloc — bundled third-party program

This folder contains **cloc** (Count Lines of Code), redistributed unmodified.

| File | Description |
|---|---|
| `cloc-2.10.pl` | cloc 2.10 Perl source (the complete corresponding source code) |
| `cloc-2.10.exe` | cloc 2.10 Windows executable, as published by the cloc project (built from the same source with PAR::Packer; it bundles a Perl runtime) |
| `COPYING-GPL-2.0.txt` | Full text of the GNU General Public License, version 2 |

- Copyright (C) 2006-2026 Al Danial &lt;al.danial@gmail.com&gt;
- Homepage and source: <https://github.com/AlDanial/cloc>
- License: GNU General Public License, version 2 or (at your option) any later version.
  cloc also includes code from SLOCCount (David Wheeler), Regexp::Common (Damian Conway and Abigail),
  Win32::Autoglob (Sean M. Burke) and Algorithm::Diff (Tye McQueen); see the header of `cloc-2.10.pl`.

```
This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.
```

LOC_Mgmt runs cloc as a separate program (a child process with command-line arguments)
and does not link to or modify it. The rest of this repository is under the MIT License
(see `../LICENSE`); that license does not apply to the files in this folder.

To use a different cloc version, place `cloc-<version>.exe` (or `.pl`) from the official
releases in this folder and keep this notice and the license text with it.
