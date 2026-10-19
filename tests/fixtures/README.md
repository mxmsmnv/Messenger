# Synthetic audio fixture

`silence-aac.m4a` contains 0.5 seconds of generated silence, mono AAC at 44.1 kHz,
encoded by macOS `afconvert` (`-f m4af -d aac -b 64000`) from an all-zero PCM WAV.
It contains no microphone, user or third-party recording. The complete real M4A
container is retained so PHP/libmagic detects its actual media type; this is not
an extension-only or mocked MIME test. The observed macOS PHP/libmagic signature
is `audio/x-m4a`; canonical MP4-family signatures are also valid across platforms.
