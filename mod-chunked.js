// Enhanced video frame loader with chunked/segmented loading support
// Based on mod.js but with added capabilities for memory-efficient segment loading

export default function getVideoFramesChunked(opts = {}) {
  // Default options
  const options = {
    videoUrl: opts.videoUrl,
    onFrame: opts.onFrame,
    onConfig: opts.onConfig,
    onFinish: opts.onFinish,
    onSegmentComplete: opts.onSegmentComplete,
    startTime: opts.startTime || 0,
    duration: opts.duration || null, // null means load entire video
    maxFrames: opts.maxFrames || null, // Limit number of frames to load
    ...opts
  };

  let onFinishResolver;
  let onFinishPromise = new Promise(r => onFinishResolver = r);
  let frameCount = 0;
  let shouldStop = false;

  const decoder = new VideoDecoder({
    output: async (frame) => {
      // Check if we should stop loading frames
      if (shouldStop) {
        frame.close();
        return;
      }

      // Check frame count limit
      if (options.maxFrames && frameCount >= options.maxFrames) {
        shouldStop = true;
        frame.close();
        
        // Flush and finish
        decoder.flush().then(() => {
          if (options.onSegmentComplete) {
            options.onSegmentComplete(frameCount);
          }
          if (options.onFinish) options.onFinish();
          onFinishResolver();
        });
        return;
      }

      // Process frame
      if (options.onFrame) {
        await options.onFrame(frame);
      }
      frameCount++;
    },
    error: function(e) {
      console.error("Decoder error:", e);
    },
  });

  // Create demuxer with segment support
  const demuxer = new MP4DemuxerChunked(options.videoUrl, {
    onConfig: function(config) {
      if (options.onConfig) options.onConfig(config);
      decoder.configure(config);
    },
    onFinish: function() {
      if (options.onFinish) options.onFinish();
      onFinishResolver();
    },
    onChunk: function(chunk) {
      if (!shouldStop) {
        decoder.decode(chunk);
      }
    },
    setStatus: function(type, message) {
      // console.log("Status:", type, message);
    },
    videoDecoder: decoder,
    startTime: options.startTime,
    duration: options.duration
  });

  return onFinishPromise;
}

// Enhanced MP4 Demuxer with segment/time range support
class MP4DemuxerChunked {
  #onConfig = null;
  #onChunk = null;
  #onFinish = null;
  #setStatus = null;
  #file = null;
  #videoDecoder = null;
  #startTime = 0;
  #duration = null;
  #currentTime = 0;
  #samplesProcessed = 0;
  #targetSampleStart = 0;
  #targetSampleEnd = null;

  constructor(uri, {onConfig, onChunk, onFinish, setStatus, videoDecoder, startTime, duration}) {
    this.#onConfig = onConfig;
    this.#onChunk = onChunk;
    this.#onFinish = onFinish;
    this.#setStatus = setStatus;
    this.#videoDecoder = videoDecoder;
    this.#startTime = startTime || 0;
    this.#duration = duration;

    // Configure MP4Box for demuxing
    this.#file = MP4Box.createFile();
    this.#file.onError = error => {
      console.error("MP4Box error:", error);
      setStatus("demux", error);
    }
    this.#file.onReady = this.#onReady.bind(this);
    this.#file.onSamples = this.#onSamples.bind(this);

    // Fetch and process the file
    const fileSink = new MP4FileSinkChunked(this.#file, setStatus, startTime, duration);
    
    // Support for byte range requests if needed
    const headers = {};
    if (startTime > 0 || duration) {
      // We'll still fetch the whole file but process only needed samples
      // For true byte-range requests, you'd need server support
    }

    fetch(uri, { headers }).then(async response => {
      await response.body.pipeTo(new WritableStream(fileSink, {highWaterMark: 2}));
      await this.#videoDecoder.flush();
      if (this.#onFinish) this.#onFinish();
    }).catch(error => {
      console.error("Fetch error:", error);
      setStatus("fetch", error.message);
    });
  }

  #description(track) {
    const trak = this.#file.getTrackById(track.id);
    for (const entry of trak.mdia.minf.stbl.stsd.entries) {
      if (entry.avcC || entry.hvcC) {
        const stream = new DataStream(undefined, 0, DataStream.BIG_ENDIAN);
        if (entry.avcC) {
          entry.avcC.write(stream);
        } else {
          entry.hvcC.write(stream);
        }
        return new Uint8Array(stream.buffer, 8);
      }
    }
    throw "avcC or hvcC not found";
  }

  #onReady(info) {
    this.#setStatus("demux", "Ready");
    const track = info.videoTracks[0];

    // Calculate sample range based on time
    if (track) {
      const timescale = track.timescale;
      const sampleDuration = track.samples_duration / track.nb_samples;
      
      // Calculate start and end samples based on time
      if (this.#startTime > 0) {
        this.#targetSampleStart = Math.floor((this.#startTime * timescale) / sampleDuration);
      }
      
      if (this.#duration) {
        const endTime = this.#startTime + this.#duration;
        this.#targetSampleEnd = Math.ceil((endTime * timescale) / sampleDuration);
      }

      // Configure extraction options
      const extractionOptions = {
        nbSamples: this.#targetSampleEnd ? 
          (this.#targetSampleEnd - this.#targetSampleStart) : 
          Number.MAX_SAFE_INTEGER
      };

      this.#file.setExtractionOptions(track.id, null, extractionOptions);
    }

    // Generate VideoDecoderConfig
    this.#onConfig({
      codec: track.codec,
      codedHeight: track.video.height,
      codedWidth: track.video.width,
      description: this.#description(track),
    });

    // Start extraction
    this.#file.start();
    
    // If we need to seek to start time
    if (this.#startTime > 0 && this.#file.seek) {
      this.#file.seek(this.#startTime, true);
    }
  }

  async #onSamples(track_id, ref, samples) {
    // Filter samples based on time range if needed
    for (const sample of samples) {
      const sampleTime = sample.cts / sample.timescale;
      
      // Skip samples before start time
      if (this.#startTime > 0 && sampleTime < this.#startTime) {
        continue;
      }
      
      // Stop if we've exceeded duration
      if (this.#duration && sampleTime > this.#startTime + this.#duration) {
        break;
      }

      // Emit the chunk
      this.#onChunk(new EncodedVideoChunk({
        type: sample.is_sync ? "key" : "delta",
        timestamp: 1e6 * sample.cts / sample.timescale,
        duration: 1e6 * sample.duration / sample.timescale,
        data: sample.data
      }));
      
      this.#samplesProcessed++;
    }
  }
}

// Enhanced MP4FileSink with segment support
class MP4FileSinkChunked {
  #setStatus = null;
  #file = null;
  #offset = 0;
  #startTime = 0;
  #duration = null;

  constructor(file, setStatus, startTime = 0, duration = null) {
    this.#file = file;
    this.#setStatus = setStatus;
    this.#startTime = startTime;
    this.#duration = duration;
  }

  write(chunk) {
    const buffer = new ArrayBuffer(chunk.byteLength);
    new Uint8Array(buffer).set(chunk);

    buffer.fileStart = this.#offset;
    this.#offset += buffer.byteLength;

    this.#setStatus("fetch", `${(this.#offset / (1024 * 1024)).toFixed(1)} MB`);
    this.#file.appendBuffer(buffer);
  }

  close() {
    this.#setStatus("fetch", "Done");
    this.#file.flush();
  }
}

// Export utility function for getting video metadata
export async function getVideoMetadata(videoUrl) {
  return new Promise((resolve, reject) => {
    const video = document.createElement('video');
    video.src = videoUrl;
    video.addEventListener('loadedmetadata', () => {
      const metadata = {
        duration: video.duration,
        width: video.videoWidth,
        height: video.videoHeight,
        // Estimate frame count (assuming 30fps as default, actual fps would need parsing)
        estimatedFrames: Math.floor(video.duration * 30)
      };
      video.remove();
      resolve(metadata);
    });
    video.addEventListener('error', (e) => {
      video.remove();
      reject(new Error('Failed to load video metadata'));
    });
  });
}

// Re-export the MP4Box and DataStream that are embedded in mod.js
// These would need to be available for the chunked demuxer to work
// Since they're included in mod.js, we assume they're globally available
if (typeof MP4Box === 'undefined' || typeof DataStream === 'undefined') {
  console.warn('MP4Box or DataStream not found. Make sure mod.js is loaded first.');
}