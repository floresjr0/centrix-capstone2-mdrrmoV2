# Android Biometric Integration (Median WebView)

Copy these files into your **Median-generated Android project**. This uses native `BiometricPrompt` + Android Keystore — **not** Median's paid biometric plugin.

## Gradle dependencies

Add to **app/build.gradle** (or `build.gradle.kts`):

```gradle
dependencies {
    implementation "androidx.biometric:biometric:1.1.0"
}
```

## Files to copy


| Source (this repo)              | Target (Median Android app)                                                |
| ------------------------------- | -------------------------------------------------------------------------- |
| `BiometricCredentialManager.kt` | `app/src/main/java/<your.package>/biometric/BiometricCredentialManager.kt` |
| `MDRRMOBiometricBridge.kt`      | `app/src/main/java/<your.package>/biometric/MDRRMOBiometricBridge.kt`      |


Adjust the `package` line at the top of each file to match your app.

## WebView registration (MainActivity or Median WebView setup)

Register the bridge **only for your trusted MDRRMO origin**:

```kotlin
import android.webkit.WebView
import your.package.biometric.MDRRMOBiometricBridge

// After WebView is created:
val bridge = MDRRMOBiometricBridge(
    activity = this,
    webView = webView,
    apiBaseUrl = "https://YOUR-DOMAIN/path/to/mdrrmo/api/device/"
)
webView.addJavascriptInterface(bridge, "MDRRMOBiometric")
```

For local XAMPP testing:

```kotlin
apiBaseUrl = "http://10.0.2.2/4th%20year/git/mdrrmo/api/device/"
```

Use `10.0.2.2` from the Android emulator to reach host machine localhost.

## Bridge methods (called from JavaScript)


| Method                           | Description                                               |
| -------------------------------- | --------------------------------------------------------- |
| `isBiometricAvailable()`         | Hardware + enrolled biometrics check                      |
| `hasBiometricCredential()`       | Local keystore credential exists                          |
| `getRegisteredEmail()`           | Email tied to local credential (not secret)               |
| `enableBiometric()`              | After password login session; registers token server-side |
| `authenticateWithBiometric()`    | Fingerprint login → `authenticate.php` → redirect         |
| `disableBiometric()`             | Revokes server token + clears local keystore              |
| `onDifferentAccountLogin(email)` | Clears credential when another email is typed             |


## Security notes

- Raw device token stays in Android Keystore (biometric-protected).
- Fingerprint data never leaves the device.
- Bridge should only be attached to WebViews loading your MDRRMO URL.
- HTTPS required in production (`usesCleartextTraffic` only for dev).

## Build

From your Android project root:

```bat
gradlew.bat clean
gradlew.bat assembleDebug
```

APK output: `app/build/outputs/apk/debug/app-debug.apk`