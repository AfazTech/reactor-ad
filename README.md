# Reactor Ad

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![Reactor](https://img.shields.io/badge/Reactor-Framework-orange.svg)](https://github.com/afaztech/reactor)

Ad slot management for [Reactor](https://github.com/afaztech/reactor) Telegram bots.

## Features

- Named ad slots
- Deterministic delivery per call
- Probabilistic delivery per call
- Configurable delivery probability per slot
- Copy or forward strategy per slot
- Enable or disable each slot independently
- Configure source chat and message per slot
- Clear a slot without touching other slots

## Requirements

- `afaztech/reactor-framework` ^0.5.0

## Installation

```bash
composer require afaztech/reactor-ad
./reactor migrate
```

## Usage

```php
use AfazTech\ReactorAd\Contracts\AdManagerInterface;

$ads = $container->get(AdManagerInterface::class);

// Create a slot and configure it.
$ads->createSlot('welcome');
$ads->setSource('welcome', 'copy', -1001234567890, 42);
$ads->setProbability('welcome', 30);

// Deliver from your own handlers / listeners.
$ads->send('welcome', $userId);        // deterministic
$ads->sendRandom('welcome', $userId);  // probabilistic (30%)
```

## Documentation

For detailed documentation, see [DOCUMENT.md](DOCUMENT.md).

## Contributing

Contributions are welcome. Please read the contribution guidelines.

## License

This project is licensed under the [MIT License](LICENSE).