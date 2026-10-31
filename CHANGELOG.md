# Changelog

All notable changes to this project are documented in this file.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added

- Masked gift card lines ("Gift card ••••1234") for Mageplaza gift cards on the cart totals, order, invoice and credit
  memo totals and PDF totals; totals position for the `gift_card` segment.
- Translations `en_US` and `de_DE`.

### Changed

- The base-currency amount of a gift card line prefers the amount collected on the quote address and falls back to the
  quote rate.
