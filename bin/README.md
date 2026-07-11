# Native engine binaries

This directory is where `NativeEngine::locate()` looks for the platform-native
`maildeno-engine` executable by default — `MaildenoClient` calls this
automatically the first time you render, so nothing needs configuring as long
as the right binary for your platform is here.

Expected layout:

```
bin/
├── windows-x64/engine.exe
├── linux-x64/engine     (built against musl, statically linked)
├── linux-arm64/engine   (built against musl, statically linked)
├── macos-x64/engine
└── macos-arm64/engine   (ad-hoc code-signed, or it won't launch)
```
