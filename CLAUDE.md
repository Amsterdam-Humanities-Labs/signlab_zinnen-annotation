# signlab_zinnen-annotation - Sign Language Annotation Tool

## Overview
This is a web-based sign language annotation tool for creating and editing subtitle/gloss tracks for sign language videos. The system allows users to synchronize Dutch text, Signbank glosses, and gesture-by-gesture annotations with video timelines.

## Key Files

### Frontend Components
- **zinnen.html**: the sentence table. The editors (subBeta8, 3DAnn3) live in `signlab_annotation-editors`.

### Backend API
- **getZinnen.php**: Core PHP API handling all database operations and file management
  - Manages sentences, videos, EAF files, and subtitle data
  - Handles CRUD operations for sign language annotations
  - Processes EAF (ELAN Annotation Format) files for linguistic tiers

## Core Functionality

### Video & Timeline Management
- Video playback with synchronized subtitle tracks
- Timeline-based editing with drag/drop subtitle positioning  
- Multiple annotation tiers: Nederlands (Dutch), Signbank ID glossen, Gebaar-voor-gebaar
- Canvas-based video rendering with frame-by-frame navigation
- Zoom/scroll controls for precise timeline editing

### Database Operations (getZinnen.php actions)
- `fetchSentences`: Retrieve sentence data with associated videos
- `fetchRow`: Get detailed row data including EAF file statistics
- `saveSubtitlesAndEAFFiles`: Save subtitle tracks and generate EAF files
- `deleteVideo`/`deleteZin`/`deleteEAF`: Remove content and files
- `editZin`: Update sentence data
- `uploadEAF`/`downloadEAF`: Handle EAF file operations
- `findGloss`/`findgvg`: Search functionality for glosses
- `listMocapFiles`: List zin videos with their latest mocap take, baked GLB and gloss SRT, filtered by MCP status columns (see `mocapFiles.php`)

### File Structure
- **eaf/zin/**: Contains EAF annotation files and SRT subtitle exports
- **api/**: Additional API endpoints and caching
- Video files are managed through database references

## Technical Stack
- Frontend: HTML5, Canvas API, Bootstrap, jQuery
- Backend: PHP with MySQLi
- File Formats: EAF (ELAN), SRT (SubRip), MP4 videos
- Database: MySQL for sentences, videos, and annotation metadata

## Annotation Workflow
1. Load video file and parse frames
2. Create subtitle segments on timeline
3. Add text annotations for multiple tiers
4. Export to EAF format for linguistic analysis
5. Generate SRT files for video subtitles

## Development Notes
- Uses frame-by-frame video processing for precise timing
- Supports multiple simultaneous annotation tracks
- Built-in backup system for EAF files
- Responsive design for various screen sizes
- Real-time preview of gloss/gesture annotations
- `/web/gebarenoverleg_media` is an rclone mount where `find` silently returns nothing; use `scandir`/`glob` for any directory enumeration there
- MCP status columns live on `sentences`, but mocap/SRT rows are `videos` — a sentence may have several videos, so always state which unit a count refers to

## Common Operations
- **Creating subtitles**: Use timeline interface to add/edit subtitle boxes
- **Exporting data**: Save annotations to EAF/SRT formats  
- **File management**: Upload/download EAF files, manage video associations
- **Search**: Find specific glosses or gesture sequences