package com.mdrrmo.app.biometric

import android.os.Handler
import android.os.Looper
import android.util.Log
import android.webkit.JavascriptInterface
import android.webkit.WebView
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import org.json.JSONObject
import java.io.BufferedReader
import java.io.OutputStreamWriter
import java.net.HttpURLConnection
import java.net.URL
import java.util.concurrent.CountDownLatch
import java.util.concurrent.TimeUnit
import java.util.concurrent.atomic.AtomicBoolean

/**
 * JavaScript bridge for MDRRMO citizen fingerprint login.
 * Attach ONLY to WebViews loading the trusted MDRRMO site.
 */
class MDRRMOBiometricBridge(
    private val activity: FragmentActivity,
    private val webView: WebView,
    private val apiBaseUrl: String
) {
    private val credentialManager = BiometricCredentialManager(activity)
    private val mainHandler = Handler(Looper.getMainLooper())

    @JavascriptInterface
    fun isBiometricAvailable(): Boolean {
        val authenticators = BiometricManager.Authenticators.BIOMETRIC_STRONG
        val result = BiometricManager.from(activity).canAuthenticate(authenticators)
        return result == BiometricManager.BIOMETRIC_SUCCESS
    }

    @JavascriptInterface
    fun hasBiometricCredential(): Boolean = credentialManager.hasCredential()

    @JavascriptInterface
    fun getRegisteredEmail(): String? = credentialManager.getRegisteredEmail()

    @JavascriptInterface
    fun onDifferentAccountLogin(newEmail: String) {
        val registered = credentialManager.getRegisteredEmail()?.lowercase()
        if (registered.isNullOrBlank()) return
        if (registered == newEmail.trim().lowercase()) return
        try {
            revokeOnServer(credentialManager.getOrCreateDeviceId())
        } catch (_: Exception) {
            // Best effort — local clear still prevents wrong-account fingerprint UI.
        }
        credentialManager.clearCredential()
    }

    @JavascriptInterface
    fun enableBiometric(): String {
        return try {
            if (!isBiometricAvailable()) {
                return jsonResult(false, "unavailable")
            }
            val plainToken = credentialManager.generateDeviceToken()
            val cipher = credentialManager.getEncryptCipher()
            val crypto = BiometricPrompt.CryptoObject(cipher)

            if (!promptBiometric(
                    title = "Enable fingerprint login",
                    subtitle = "Confirm your fingerprint to save this device",
                    crypto = crypto
                )
            ) {
                return jsonResult(false, "cancelled")
            }

            credentialManager.storeEncryptedToken(cipher, plainToken)
            val deviceId = credentialManager.getOrCreateDeviceId()
            val registerResponse = postJson(
                apiBaseUrl + "register.php",
                JSONObject()
                    .put("device_id", deviceId)
                    .put("device_token", plainToken)
            )
            if (!registerResponse.optBoolean("success", false)) {
                credentialManager.clearCredential()
                return jsonResult(false, "register_failed")
            }
            val email = registerResponse.optString("email", "")
            if (email.isNotBlank()) credentialManager.saveRegisteredEmail(email)
            jsonResult(true, null)
        } catch (e: Exception) {
            Log.w(TAG, "enableBiometric failed", e)
            jsonResult(false, "error")
        }
    }

    @JavascriptInterface
    fun authenticateWithBiometric(): String {
        return try {
            if (!isBiometricAvailable() || !credentialManager.hasCredential()) {
                return jsonResult(false, "unavailable")
            }

            val cipher = credentialManager.getDecryptCipher()
            val crypto = BiometricPrompt.CryptoObject(cipher)
            if (!promptBiometric(
                    title = "Login with fingerprint",
                    subtitle = "Confirm your fingerprint to sign in",
                    crypto = crypto
                )
            ) {
                return jsonResult(false, "cancelled")
            }

            val plainToken = credentialManager.readDecryptedToken(cipher)
            val deviceId = credentialManager.getOrCreateDeviceId()
            val authResponse = postJson(
                apiBaseUrl + "authenticate.php",
                JSONObject()
                    .put("device_id", deviceId)
                    .put("device_token", plainToken)
            )
            if (!authResponse.optBoolean("success", false)) {
                return jsonResult(false, "auth_failed")
            }
            JSONObject()
                .put("success", true)
                .put("redirect", authResponse.optString("redirect", ""))
                .toString()
        } catch (e: Exception) {
            Log.w(TAG, "authenticateWithBiometric failed", e)
            jsonResult(false, "error")
        }
    }

    @JavascriptInterface
    fun disableBiometric(): String {
        return try {
            val deviceId = credentialManager.getOrCreateDeviceId()
            try {
                revokeOnServer(deviceId)
            } catch (_: Exception) {
                // Still clear local credential.
            }
            credentialManager.clearCredential()
            jsonResult(true, null)
        } catch (e: Exception) {
            Log.w(TAG, "disableBiometric failed", e)
            jsonResult(false, "error")
        }
    }

    private fun revokeOnServer(deviceId: String) {
        postJson(
            apiBaseUrl + "revoke.php",
            JSONObject().put("device_id", deviceId)
        )
    }

    private fun promptBiometric(
        title: String,
        subtitle: String,
        crypto: BiometricPrompt.CryptoObject
    ): Boolean {
        val latch = CountDownLatch(1)
        val success = AtomicBoolean(false)

        mainHandler.post {
            val prompt = BiometricPrompt(
                activity,
                ContextCompat.getMainExecutor(activity),
                object : BiometricPrompt.AuthenticationCallback() {
                    override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) {
                        success.set(true)
                        latch.countDown()
                    }

                    override fun onAuthenticationError(errorCode: Int, errString: CharSequence) {
                        latch.countDown()
                    }

                    override fun onAuthenticationFailed() {
                        // Keep prompt open; user can retry.
                    }
                }
            )
            val info = BiometricPrompt.PromptInfo.Builder()
                .setTitle(title)
                .setSubtitle(subtitle)
                .setNegativeButtonText("Cancel")
                .setAllowedAuthenticators(BiometricManager.Authenticators.BIOMETRIC_STRONG)
                .build()
            prompt.authenticate(info, crypto)
        }

        latch.await(2, TimeUnit.MINUTES)
        return success.get()
    }

    private fun postJson(urlString: String, body: JSONObject): JSONObject {
        val url = URL(urlString)
        val conn = (url.openConnection() as HttpURLConnection).apply {
            requestMethod = "POST"
            doOutput = true
            setRequestProperty("Content-Type", "application/json; charset=utf-8")
            connectTimeout = 15000
            readTimeout = 15000
        }

        val cookie = android.webkit.CookieManager.getInstance().getCookie(urlString)
        if (!cookie.isNullOrBlank()) {
            conn.setRequestProperty("Cookie", cookie)
        }

        OutputStreamWriter(conn.outputStream, Charsets.UTF_8).use { writer ->
            writer.write(body.toString())
        }

        val status = conn.responseCode
        val setCookies = conn.headerFields["Set-Cookie"] ?: emptyList()
        for (header in setCookies) {
            android.webkit.CookieManager.getInstance().setCookie(urlString, header)
        }
        if (setCookies.isNotEmpty()) {
            android.webkit.CookieManager.getInstance().flush()
        }

        val stream = if (status in 200..299) conn.inputStream else conn.errorStream
        val text = stream.bufferedReader().use(BufferedReader::readText)
        conn.disconnect()
        return try {
            JSONObject(text)
        } catch (e: Exception) {
            Log.w(TAG, "Invalid JSON from $urlString")
            JSONObject().put("success", false)
        }
    }

    private fun jsonResult(success: Boolean, error: String?): String {
        val obj = JSONObject().put("success", success)
        if (!error.isNullOrBlank()) obj.put("error", error)
        return obj.toString()
    }

    companion object {
        private const val TAG = "MDRRMOBiometric"
    }
}
