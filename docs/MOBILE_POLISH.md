# Mobile and keyboard accessibility

The builder uses dynamic viewport height with a legacy fallback, wraps toolbar
controls on phones, provides 44px button targets, and reserves the bottom safe
area in the properties panel.

Keyboard help traps Tab on its close button, closes with Escape or a backdrop
click, restores the initiating focus, and prevents duplicate dialogs.

JavaScript syntax checking and the static accessibility scan pass. The scan
reports zero errors and 28 warnings. Rendered mobile and WordPress verification
remain required before release.
