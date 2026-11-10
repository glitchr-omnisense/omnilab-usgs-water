# Changelog

## [Unreleased]

### Added

- `policy.json`: the service's usage policy as data (limits, licence, credit, what a request needs), read at its pages on 2026-11-10 and named in `composer.json` (`extra.omnisense.policy`); the core honours it on every request.

### Fixed

- A Point with no monitoring location near is a series without points per variable, at the point,
  where it was an empty list (`SourceInterface::series()`); an unknown location is still an empty list.
