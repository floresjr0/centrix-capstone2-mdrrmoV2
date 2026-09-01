package com.mdrrmo.app.biometric

import android.content.Context
import android.content.SharedPreferences
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import java.security.KeyStore
import java.util.UUID
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * Stores a random device auth token encrypted with Android Keystore (AES/GCM).
 * Unlock requires BiometricPrompt via BiometricPrompt.CryptoObject.
 * No fingerprint data is stored — only the encrypted token bytes.
 */
class BiometricCredentialManager(context: Context) {

    private val prefs: SharedPreferences =
        context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    fun getOrCreateDeviceId(): String {
        val existing = prefs.getString(KEY_DEVICE_ID, null)
        if (!existing.isNullOrBlank()) return existing
        val id = UUID.randomUUID().toString().replace("-", "")
        prefs.edit().putString(KEY_DEVICE_ID, id).apply()
        return id
    }

    fun getRegisteredEmail(): String? = prefs.getString(KEY_REGISTERED_EMAIL, null)

    fun hasCredential(): Boolean {
        return prefs.contains(KEY_ENCRYPTED_TOKEN) && prefs.contains(KEY_IV)
    }

    fun saveRegisteredEmail(email: String) {
        prefs.edit().putString(KEY_REGISTERED_EMAIL, email.trim().lowercase()).apply()
    }

    fun generateDeviceToken(): String {
        val bytes = ByteArray(32)
        java.security.SecureRandom().nextBytes(bytes)
        return Base64.encodeToString(bytes, Base64.NO_WRAP)
    }

    fun getEncryptCipher(): Cipher {
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(Cipher.ENCRYPT_MODE, getOrCreateSecretKey())
        return cipher
    }

    fun getDecryptCipher(): Cipher {
        val iv = prefs.getString(KEY_IV, null) ?: throw IllegalStateException("missing_iv")
        val cipher = Cipher.getInstance(TRANSFORMATION)
        cipher.init(
            Cipher.DECRYPT_MODE,
            getOrCreateSecretKey(),
            GCMParameterSpec(GCM_TAG_LENGTH, Base64.decode(iv, Base64.NO_WRAP))
        )
        return cipher
    }

    fun storeEncryptedToken(cipher: Cipher, plainToken: String) {
        val encrypted = cipher.doFinal(plainToken.toByteArray(Charsets.UTF_8))
        prefs.edit()
            .putString(KEY_ENCRYPTED_TOKEN, Base64.encodeToString(encrypted, Base64.NO_WRAP))
            .putString(KEY_IV, Base64.encodeToString(cipher.iv, Base64.NO_WRAP))
            .apply()
    }

    fun readDecryptedToken(cipher: Cipher): String {
        val encryptedB64 = prefs.getString(KEY_ENCRYPTED_TOKEN, null)
            ?: throw IllegalStateException("missing_token")
        val decrypted = cipher.doFinal(Base64.decode(encryptedB64, Base64.NO_WRAP))
        return String(decrypted, Charsets.UTF_8)
    }

    fun clearCredential() {
        prefs.edit()
            .remove(KEY_ENCRYPTED_TOKEN)
            .remove(KEY_IV)
            .remove(KEY_REGISTERED_EMAIL)
            .apply()
    }

    private fun getOrCreateSecretKey(): SecretKey {
        val keyStore = KeyStore.getInstance(ANDROID_KEYSTORE).apply { load(null) }
        if (keyStore.containsAlias(KEY_ALIAS)) {
            val entry = keyStore.getEntry(KEY_ALIAS, null) as KeyStore.SecretKeyEntry
            return entry.secretKey
        }

        val spec = KeyGenParameterSpec.Builder(
            KEY_ALIAS,
            KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT
        )
            .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
            .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
            .setUserAuthenticationRequired(true)
            .setInvalidatedByBiometricEnrollment(true)
            .build()

        val generator = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, ANDROID_KEYSTORE)
        generator.init(spec)
        return generator.generateKey()
    }

    companion object {
        private const val PREFS_NAME = "mdrrmo_biometric_prefs"
        private const val KEY_DEVICE_ID = "device_id"
        private const val KEY_REGISTERED_EMAIL = "registered_email"
        private const val KEY_ENCRYPTED_TOKEN = "encrypted_device_token"
        private const val KEY_IV = "token_iv"
        private const val KEY_ALIAS = "mdrrmo_biometric_key"
        private const val ANDROID_KEYSTORE = "AndroidKeyStore"
        private const val TRANSFORMATION = "AES/GCM/NoPadding"
        private const val GCM_TAG_LENGTH = 128
    }
}
