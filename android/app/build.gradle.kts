import java.util.Properties
import org.jetbrains.kotlin.gradle.dsl.JvmTarget

plugins {
    alias(libs.plugins.android.application)
    alias(libs.plugins.kotlin.android)
}

// Firebase is optional while trying the app: without google-services.json it is a plain
// WebView of BetLedger and push notifications stay off (see README.md).
if (file("google-services.json").exists()) {
    apply(plugin = libs.plugins.google.services.get().pluginId)
}

// The server the app opens. Set `betledger.url` in android/local.properties (or pass
// -Pbetledger.url=…); the default is a `php artisan serve` on the emulator's host.
val localProperties = Properties().apply {
    val file = rootProject.file("local.properties")
    if (file.exists()) {
        file.inputStream().use { load(it) }
    }
}
val betledgerUrl = (findProperty("betledger.url") as String?)
    ?: localProperties.getProperty("betledger.url")
    ?: "http://10.0.2.2:8000"

android {
    namespace = "com.betledger.app"
    compileSdk = 35

    defaultConfig {
        applicationId = "com.betledger.app"
        minSdk = 26
        targetSdk = 35
        versionCode = 1
        versionName = "0.1.0"

        buildConfigField("String", "BETLEDGER_URL", "\"${betledgerUrl.trimEnd('/')}\"")
    }

    buildTypes {
        release {
            isMinifyEnabled = false
        }
    }

    buildFeatures {
        buildConfig = true
    }

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
    }
}

kotlin {
    compilerOptions {
        jvmTarget.set(JvmTarget.JVM_17)
    }
}

dependencies {
    implementation(libs.androidx.core.ktx)
    implementation(libs.androidx.activity.ktx)
    implementation(platform(libs.firebase.bom))
    implementation(libs.firebase.messaging)
}
