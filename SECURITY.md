# Security policy

## Supported version

Security fixes are applied to the latest release. Upgrade before reporting a
problem that may already have been corrected.

## Reporting a vulnerability

Please do not publish exploitable details in a public issue. Use GitHub's
private vulnerability reporting feature for this repository. Include the
affected version, WordPress and PHP versions, reproduction steps, and the
security impact. Do not include production credentials, private media, database
dumps, recovery manifests, or server logs containing sensitive data.

The plugin deliberately requires administrator capabilities, WordPress REST
nonces, typed confirmation for file actions, and private recovery storage
outside public web roots. A report that bypasses any of these controls is
especially useful.
