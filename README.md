# BRIX Image Optimizer 🚀

**BRIX Image Optimizer** is a production-ready, high-performance WordPress plugin designed to seamlessly convert JPG and PNG images to the modern **WebP** format. Built with a focus on speed, server safety, and clean file management.

Developed by **Jatin Beniwal** for [BRIXLY.com](https://brixly.com).

---

## 🌟 Key Features

- **Hybrid Optimization**: 
  - **Runtime Generation**: Automatically creates WebP versions when a page is visited (Lazy Loading).
  - **Bulk Tool**: Batch process your entire existing Media Library with one click.
- **Smart Mirroring**: Stores optimized images in a dedicated `/wp-content/uploads/brix-optimized/` folder, mirroring your original directory structure.
- **Non-Destructive**: Your original JPG/PNG files are **NEVER** touched or modified.
- **Zero-Config**: Works out of the box. Just activate and start saving disk space.
- **Media Library Integration**: Includes a custom "WebP Status" column to track optimization progress at a glance.
- **Live Statistics**: Dashboard widget showing real-time data on images optimized and total disk space saved.
- **Safe Fallback**: Automatically reverts to original images if the plugin is deactivated.
- **Full Cleanup**: Automatically wipes all optimized data upon uninstallation to keep your server clean.

---

## 🚀 Installation

1. Download the `brix-image-optimizer.zip`.
2. Go to **Plugins > Add New** in your WordPress dashboard.
3. Click **Upload Plugin** and select the ZIP file.
4. **Activate** the plugin.
5. Go to **Settings > BRIX Optimizer** to see your stats or start a bulk scan.

---

## 🛠 Technical Details

- **Requirements**: PHP 7.4+, GD Library or Imagick support.
- **Logic**: Uses a content filter to swap URLs at runtime. It checks for file existence before serving to ensure no broken images.
- **Architecture**: Separates optimized assets from original uploads to simplify backups and site migrations.

---

## 👨‍💻 Developer Information

- **Developer**: Jatin Beniwal
- **Website**: [jatinbeniwal.in](https://jatinbeniwal.in)
- **Company**: [BRIXLY](https://brixly.com)

---

## 📜 License

This project is licensed under the GPL-2.0+ License.
