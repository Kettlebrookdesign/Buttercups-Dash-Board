import fs from 'fs-extra';
import path from 'path';
import archiver from 'archiver';
import { execSync } from 'child_process';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const projectRoot = path.resolve(__dirname, '..');

/**
 * Keeps only the latest N versions of the plugin zip.
 */
async function cleanOldZips(outputDir, pluginSlug, keepCount = 5) {
  try {
    const files = await fs.readdir(outputDir);
    
    // Filter files matching the pattern and get their stats
    const zipFiles = files
      .filter(file => file.startsWith(`${pluginSlug}-v`) && file.endsWith('.zip'))
      .map(file => {
        const filePath = path.join(outputDir, file);
        const stats = fs.statSync(filePath);
        return {
          name: file,
          path: filePath,
          mtime: stats.mtime.getTime()
        };
      })
      // Sort descending by modified time (newest first)
      .sort((a, b) => b.mtime - a.mtime);

    if (zipFiles.length > keepCount) {
      const toDelete = zipFiles.slice(keepCount);
      console.log('\x1b[33m%s\x1b[0m', `Retention policy: Keeping latest ${keepCount} versions. Cleaning up ${toDelete.length} old package(s)...`);
      
      for (const file of toDelete) {
        await fs.remove(file.path);
        console.log(`  Deleted: ${file.name}`);
      }
    }
  } catch (err) {
    console.warn(`\x1b[31m%s\x1b[0m`, `  Warning: Could not clean up old ZIPs: ${err.message}`);
  }
}

async function packagePlugin() {
  try {
    // 1. Read package.json for version information
    const pkgPath = path.join(projectRoot, 'package.json');
    const pkg = await fs.readJson(pkgPath);
    const version = pkg.version;
    
    // Use the folder name as the plugin slug for the zip and internal directory
    const pluginSlug = path.basename(projectRoot);
    const zipFileName = `${pluginSlug}-v${version}.zip`;
    
    // Target output directory: ../Buttercups Bookly Plugin/
    const outputDir = path.resolve(projectRoot, '..', 'Buttercups Bookly Plugin');
    await fs.ensureDir(outputDir);
    const outputPath = path.resolve(outputDir, zipFileName);

    console.log(`\x1b[36m%s\x1b[0m`, `Packaging ${pluginSlug} version ${version}...`);

    // 2. Run production build
    console.log('\x1b[33m%s\x1b[0m', 'Running production build (npm run build)...');
    execSync('npm run build', { stdio: 'inherit', cwd: projectRoot });

    // 3. Create a temporary directory for packaging
    const tempDir = path.join(projectRoot, 'temp-package');
    const pluginDir = path.join(tempDir, pluginSlug);
    
    if (await fs.exists(tempDir)) {
      await fs.remove(tempDir);
    }
    await fs.ensureDir(pluginDir);

    // 4. Define files and folders to include
    const includes = [
      'buttercups-dashboard.php',
      'includes',
      'build'
    ];

    console.log('\x1b[33m%s\x1b[0m', 'Staging production files...');
    for (const item of includes) {
      const src = path.join(projectRoot, item);
      const dest = path.join(pluginDir, item);
      
      if (await fs.exists(src)) {
        await fs.copy(src, dest);
        console.log(`  Included: ${item}`);
      } else {
        console.warn(`\x1b[31m%s\x1b[0m`, `  Warning: ${item} not found, skipping.`);
      }
    }

    // 5. Create the zip archive
    console.log('\x1b[33m%s\x1b[0m', `Creating versioned zip in ${path.basename(outputDir)}...`);
    const output = fs.createWriteStream(outputPath);
    const archive = archiver('zip', {
      zlib: { level: 9 } // Maximum compression
    });

    return new Promise((resolve, reject) => {
      output.on('close', async () => {
        console.log(`\n\x1b[32m%s\x1b[0m`, `✨ Package created successfully!`);
        console.log(`\x1b[32m%s\x1b[0m`, `📍 Location: ${outputPath}`);
        console.log(`\x1b[32m%s\x1b[0m`, `📦 Size: ${(archive.pointer() / 1024 / 1024).toFixed(2)} MB`);
        
        // 6. Maintenance: Clean up old versions
        await cleanOldZips(outputDir, pluginSlug);

        // 7. Clean up temp files
        console.log('\nCleaning up temporary files...');
        await fs.remove(tempDir);
        console.log('Done!');
        resolve();
      });

      archive.on('error', (err) => {
        reject(err);
      });

      archive.pipe(output);

      // Add the plugin directory to the archive
      archive.directory(pluginDir, pluginSlug);

      archive.finalize();
    });

  } catch (error) {
    console.error('\n\x1b[31m%s\x1b[0m', '❌ Packaging failed:');
    console.error(error.message);
    process.exit(1);
  }
}

packagePlugin();
