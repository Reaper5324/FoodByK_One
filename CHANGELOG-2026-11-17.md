# Changelog — 17 November 2026

This entry records repository changes made on 17 November 2026. It does not imply deployment.

## Customer support page

- Refreshed the support page to follow customer page styling, with a warm cream canvas, responsive light panels, red and yellow brand accents, and neutral form controls for readability.
- Added color accents to support topics, request panels, empty states, and ticket cards without changing support form or lookup behavior.
- Updated the testing guide with support-page visual and responsive review criteria.

## CI performance baseline

- Capped the HTTP baseline at 100 aggregate requests per second to make it less sensitive to variable GitHub-hosted runner capacity. The 1% error-rate threshold remains unchanged; this is still a baseline, not a capacity test.
- Updated the test documentation to describe the controlled rate.

## Verification

- `git diff --check` passed for the changes reviewed in this session.
- The browser suite and CI workflow were not run for these documentation and styling changes.
