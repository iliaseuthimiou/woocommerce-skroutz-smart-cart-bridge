# Security policy

## Supported versions

Security fixes are provided for the latest tagged release.

## Reporting a vulnerability

Please use GitHub's private vulnerability reporting feature for this repository. Do not publish exploit details in a public issue before a fix is available.

## Webhook credential

The complete webhook URL is a credential because it contains the secret required for incoming requests.

- Do not post the URL in issues, screenshots, logs, or documentation.
- Regenerate it immediately if it is exposed.
- After regeneration, update the URL in the Skroutz merchant panel; the previous URL stops working immediately.
- Keep WordPress administrator accounts and the database protected because authorized administrators can view the URL.
