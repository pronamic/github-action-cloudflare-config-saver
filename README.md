# GitHub Action Cloudflare Config Saver

_A GitHub Action that exports Cloudflare zone configuration (DNS, rulesets, settings) to versioned JSON files in your repository._

Useful for backing up, tracking changes, and simplifying Cloudflare config management.

## Table of Contents

- [Features](#features)
- [Quick Start](#quick-start)
- [Inputs](#inputs)
- [Security](#security)
- [Contributions](#contributions)
- [Terraform](#terraform)
- [Links](#links)

## Features

- Fetches and stores rulesets, settings, and DNS records from Cloudflare
- Redacts dynamic fields such as export timestamps and SOA serial numbers
- Saves JSON files to a configurable directory
- Automatically commits, pushes, and creates a pull request
- Can be run manually or scheduled

## Quick Start

Create a file like `.github/workflows/save-cloudflare-config.yml`:

```yml
name: Save Cloudflare configuration

on:
  schedule:
    - cron: '0 10 * * *'
  workflow_dispatch:

jobs:
  save:
    runs-on: ubuntu-latest

    permissions:
      contents: write
      id-token: write
      pull-requests: write

    steps:
      - name: Save Cloudflare config
        uses: pronamic/github-action-cloudflare-config-export@main
        env:
          GITHUB_TOKEN: ${{secrets.GITHUB_TOKEN}}
        with:
          api_token: ${{ secrets.CLOUDFLARE_API_TOKEN }}
          zone_id: ${{ vars.CLOUDFLARE_ZONE_ID }}
```

## Inputs

| Name               | Description                        | Required | Default             |
| ------------------ | ---------------------------------- | -------- | ------------------- |
| `api_token`        | Cloudflare API token               | ✅       |                     |
| `zone_id`          | Cloudflare zone ID                 | ✅       |                     |
| `target_directory` | Where to store the exported config | ❌       | `cloudflare-config` |

## Security

Store your Cloudflare API Token in [GitHub Secrets](https://docs.github.com/en/actions/security-for-github-actions/security-guides/about-secrets) (`CLOUDFLARE_API_TOKEN`). The token should have read access to rulesets and settings:

### Permissions

- Zone → Config Rules → Read
- Zone → Cache Rules → Read
- Zone → Transform Rules → Read
- Zone → Zone WAF → Read
- Zone → Zone Settings → Read
- Zone → DNS → Read
- Zone → Firewall Services → Read

## Contributions

Contributions welcome! Please open an issue or submit a PR.

## Terraform

### Why we don't use Terraform for managing Cloudflare configuration

We have used [Cloudflare's Terraform provider](https://developers.cloudflare.com/terraform/) to manage settings like firewall rules, WAF, and page rules. While powerful, it comes with practical downsides, especially when managing many websites for different clients.

### Too complex for collaborators

Terraform is not user-friendly for non-developers. The syntax and workflow are too technical for support staff or clients.

### Managing the Terraform state is a challenge

Terraform requires a central, secure state file. Sharing and maintaining this file across teams or CI systems is hard to manage. This gets more complicated when working with hundreds of zones or clients.

### Applying changes can delete and recreate resources

When Terraform applies changes, existing rulesets are often removed and replaced. This resets Cloudflare statistics, which is problematic when fine-tuning configurations.

### A simpler alternative

This GitHub Action fetches the current Cloudflare config and saves it to your Git repository. You can review changes via pull requests. No state file. No destructive updates. Just a versioned, readable history of your live Cloudflare setup.

## Links

- https://developers.cloudflare.com/api/
- https://developers.cloudflare.com/terraform/
- https://registry.terraform.io/providers/cloudflare/cloudflare/latest/docs
- https://github.com/cloudflare/terraform-provider-cloudflare
- https://docs.github.com/en/actions/security-for-github-actions/security-guides/using-secrets-in-github-actions
- https://docs.github.com/en/actions/security-for-github-actions/security-guides/about-secrets
