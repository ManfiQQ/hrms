# Vendored fonts

**Liberation Sans** — SIL Open Font License 1.1. Copyright (c) 2012 Red Hat, Inc.
Digitized data copyright (c) 2010 Google Corporation. <https://github.com/liberationfonts>

The OFL permits redistribution, including bundled with software, provided the fonts are not
sold on their own and the reserved name is not used for modified versions. Neither applies
here: they are shipped unmodified and used only to render text into activation images.

## Why these are in the repository rather than taken from the system

`ActivationImage` draws the employee's name and the token's validity period alongside the QR
code, and `imagettftext()` needs a real font file to do it.

⚠ **The Sail container has DejaVu and Liberation installed; the production VPS is a native
LEMP stack and may not.** A missing font is not a cosmetic failure — `imagettftext()` returns
false, the image cannot be produced, and **HR cannot send an activation to anybody**, which
blocks every new employee from entering the system (BR-A20, BR-A21).

Vendoring makes the output identical on a developer's machine, in CI, and on the server, and
removes a class of deployment failure whose symptom would appear far from its cause.

Two weights are kept: **Bold** for the employee's name, which is the line a recipient checks
first to see whose activation this is, and **Regular** for the validity period.
