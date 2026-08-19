#!/usr/bin/env node

const puppeteer = require('puppeteer');

const BASE_URL = 'https://signcollect.nl';
const TEST_FILENAME = 'M20241204_0100';

async function testSubBeta4() {
  console.log('🚀 Testing subBeta4.html Auto-Segmentation\n');
  console.log(`URL: ${BASE_URL}/zin/subBeta4.html?filename=${TEST_FILENAME}.mp4\n`);

  const browser = await puppeteer.launch({
    headless: true,
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--ignore-certificate-errors']
  });

  const page = await browser.newPage();

  // Capture ALL console logs
  const logs = [];
  page.on('console', msg => {
    const type = msg.type();
    const text = msg.text();
    logs.push({ type, text, time: Date.now() });

    if (type === 'error') {
      console.log(`❌ ${text}`);
    } else if (text.includes('[SSE]')) {
      console.log(`📡 ${text}`);
    }
  });

  page.on('pageerror', error => {
    console.log(`❌ PAGE ERROR: ${error.message}`);
  });

  try {
    console.log('Loading page...');
    await page.goto(`${BASE_URL}/zin/subBeta4.html?filename=${TEST_FILENAME}.mp4`, {
      waitUntil: 'networkidle2',
      timeout: 30000
    });
    console.log('✅ Page loaded\n');

    // Wait for initialization
    await new Promise(r => setTimeout(r, 2000));

    // Check if button exists
    const buttonExists = await page.$('#autoSegmentBtn');
    if (!buttonExists) {
      console.log('❌ Auto Segmentation button not found!');
      await browser.close();
      return;
    }
    console.log('✅ Auto Segmentation button found\n');

    // Take screenshot before
    await page.screenshot({ path: '/web/zin/test_before.png' });
    console.log('📸 Screenshot: test_before.png\n');

    // Click button
    console.log('🖱️  Clicking Auto Segmentation button...\n');
    await page.click('#autoSegmentBtn');

    // Wait a moment
    await new Promise(r => setTimeout(r, 1000));

    // Check modal
    const modalVisible = await page.evaluate(() => {
      const modal = document.getElementById('segmentationProgressModal');
      return window.getComputedStyle(modal).display !== 'none';
    });
    console.log(`Modal visible: ${modalVisible ? '✅ YES' : '❌ NO'}\n`);

    // Take modal screenshot
    await page.screenshot({ path: '/web/zin/test_modal.png' });
    console.log('📸 Screenshot: test_modal.png\n');

    // Wait for updates (max 60 seconds)
    console.log('⏳ Waiting for progress updates (max 60s)...\n');

    let lastProgress = 0;
    for (let i = 0; i < 60; i++) {
      await new Promise(r => setTimeout(r, 1000));

      try {
        const progress = await page.evaluate(() => {
          const bar = document.getElementById('segmentationProgressBar');
          const stage = document.getElementById('segmentationStage');
          const message = document.getElementById('segmentationProgressMessage');
          return {
            width: bar ? bar.style.width : '0%',
            stage: stage ? stage.textContent : '',
            message: message ? message.textContent : ''
          };
        });

        const pct = parseInt(progress.width) || 0;
        if (pct > lastProgress) {
          console.log(`📊 ${progress.width} - ${progress.stage} - ${progress.message}`);
          lastProgress = pct;
        }

        if (pct >= 100) {
          console.log('\n✅ Completed!\n');
          break;
        }
      } catch (e) {
        // Check if modal closed
        const visible = await page.evaluate(() => {
          const modal = document.getElementById('segmentationProgressModal');
          return modal && window.getComputedStyle(modal).display !== 'none';
        }).catch(() => false);

        if (!visible) {
          console.log('\nℹ️  Modal closed\n');
          break;
        }
      }

      if (i % 10 === 0 && i > 0) {
        console.log(`⏱️  ${i}s elapsed...`);
      }
    }

    // Final screenshot
    await page.screenshot({ path: '/web/zin/test_final.png' });
    console.log('📸 Screenshot: test_final.png\n');

    // Save logs
    require('fs').writeFileSync('/web/zin/console_logs.json', JSON.stringify(logs, null, 2));
    console.log('📄 Logs saved: console_logs.json\n');

    // Summary
    const sseStart = logs.filter(l => l.text.includes('[SSE] Starting')).length;
    const sseResponse = logs.filter(l => l.text.includes('[SSE] Response received')).length;
    const sseChunks = logs.filter(l => l.text.includes('[SSE] Received chunk')).length;
    const sseProgress = logs.filter(l => l.text.includes('[SSE] Status: progress')).length;
    const errors = logs.filter(l => l.type === 'error').length;

    console.log('📊 SUMMARY:');
    console.log(`   SSE Started: ${sseStart}`);
    console.log(`   SSE Response: ${sseResponse}`);
    console.log(`   SSE Chunks: ${sseChunks}`);
    console.log(`   Progress Updates: ${sseProgress}`);
    console.log(`   Errors: ${errors}`);

    if (sseProgress > 0) {
      console.log('\n✅ TEST PASSED - Progress updates working!');
    } else {
      console.log('\n❌ TEST FAILED - No progress updates');
    }

  } catch (error) {
    console.log(`\n❌ Error: ${error.message}`);
    console.log(error.stack);
  } finally {
    await browser.close();
  }
}

testSubBeta4().catch(console.error);
