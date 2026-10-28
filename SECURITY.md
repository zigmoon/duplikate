# Security policy

## Supported versions

| Version | Supported |
|---|---|
| 1.0.x | Yes |

## Reporting a vulnerability

Please **do not** open a public issue for a security problem.

Use GitHub's private security advisories:

<https://github.com/zigmoon/duplikate/security/advisories/new>

A useful report includes:

- the Duplikate, WordPress and PHP versions;
- a description of the problem and its impact;
- reproduction steps, ideally with the HTTP request or a screenshot;
- any mitigation you already know of.

Acknowledgment within 72 hours. A fix and a coordinated release follow as soon as possible.

## Scope

In scope:

- reading, duplicating or publishing content the current user cannot edit;
- bypassing the nonce or the capability check on any action, including the
  link printed in the block editor;
- writing outside the duplicated post — orphan rows, corrupted terms, a copy
  left behind after a failure;
- privilege escalation through the settings screen;
- open redirect through the referer handling;
- data left behind after uninstall when cleanup is enabled.

Out of scope:

- anything that requires an already-compromised administrator account;
- the behaviour of other plugins or of the theme;
- WordPress core and PHP themselves.

## What the plugin never does

Duplikate runs no SQL of its own, creates no table, registers no rewrite rule,
makes no outbound request and loads no third-party code. It uses the post,
term and metadata APIs only.
