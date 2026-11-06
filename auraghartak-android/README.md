# AuraGharTak Android — open in Android Studio

## Which folder to open

Open **only** this folder in Android Studio:

`C:\xampp\htdocs\Advanced Pharmacy\auraghartak-android`

Do **not** open the parent `Advanced Pharmacy` folder (that is Angular + PHP; Android Studio will not show **Run**).

Do **not** open `Auraghatak` (old incomplete copy, no Gradle wrapper).

**File → Open** → select `auraghartak-android` (the folder that contains `settings.gradle.kts` and `gradlew.bat`).

## If Run ▶ is missing or greyed out

1. Wait for **Gradle sync** to finish (status bar bottom). If it failed, click **Sync Project with Gradle Files** (elephant icon).
2. Top toolbar: run configuration dropdown → choose **app** (or **Add Configuration… → Android App → module app**).
3. **File → Project Structure → SDK Location**: Android SDK should be  
   `C:\Users\Admin\AppData\Local\Android\Sdk`  
   (same as `sdk.dir` in `local.properties`).
4. **File → Settings → Build, Execution, Deployment → Build Tools → Gradle**  
   **Gradle JDK** = **Embedded JDK** (`C:\Program Files\Android\Android Studio\jbr`).  
   If you see *Invalid Gradle JDK / JAVA_HOME*: click **Change Gradle JDK location** and pick **Embedded JDK**, then **Sync Project with Gradle Files**.
5. Connect a phone (USB debugging) or **Tools → Device Manager** → create/start an emulator.
6. View dropdown (top-left): switch from **Android** to **Project** if the tree looks empty.

## Command-line build (optional)

```bat
cd "C:\xampp\htdocs\Advanced Pharmacy\auraghartak-android"
set JAVA_HOME=C:\Program Files\Android\Android Studio\jbr
gradlew.bat assembleDebug
```

APK: `app\build\outputs\apk\debug\app-debug.apk`

## UI changes not showing on the phone?

1. **Correct project** — only `auraghartak-android` (package `com.paperlesspharmacy.auraghartak`). The old `Auraghatak` folder is a different app (`in.paperlesspharmacy.auraghatak`).
2. **Uninstall** the old app on the phone (Settings → Apps → AuraGharTak → Uninstall), then **Run ▶** again from Android Studio.
3. **Build → Clean Project**, then **Build → Rebuild Project**, then Run.
4. On the **login** screen, check the footer: **App version 1.2.0** (centered layout + gradient). If you still see **1.0.0** / **1.1.0** or no version line, the new APK is not installed.
5. Or install the APK manually: copy `app\build\outputs\apk\debug\app-debug.apk` to the phone and open it (allow “Install unknown apps” if asked).
