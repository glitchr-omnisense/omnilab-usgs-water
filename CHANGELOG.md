# Changelog

## [Unreleased]

### Fixed

- A Point with no monitoring location near is a series without points per variable, at the point,
  where it was an empty list (`SourceInterface::series()`); an unknown location is still an empty list.
