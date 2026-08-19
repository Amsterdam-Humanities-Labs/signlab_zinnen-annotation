# 3DAnn.html - 3D Annotation Editor Documentation

This document describes the key functions in 3DAnn.html for animation, retargeting, and annotation.

## Overview

3DAnn.html is a Babylon.js-based 3D annotation editor that loads sign language animations onto a base character model and provides timeline-based subtitle/annotation editing.

---

## Animation Functions

### `initializeBabylonScene()`
Initializes the Babylon.js engine, scene, lighting, and camera.
- Creates WebGL2 engine with antialiasing
- Sets up hemispheric and directional lights
- Configures ArcRotateCamera framed for hips-to-head view
- Starts the render loop

### `loadBaseCharacter()`
Loads the base character mesh (`glassesGuySignLab.glb`) that animations will be retargeted onto.
- Imports mesh from `blendAnims/glassesGuySignLab.glb`
- Extracts skeleton (67 bones) and morph target managers (9)
- Sets `alwaysSelectAsActiveMesh = true` on all meshes to fix clipping
- Creates root transform node and rotates character upright (90° on X axis)
- Stores references in global `character`, `skeleton`, `morphTargetManagers`

### `loadAnimationGLB(glbPath)`
Loads an animation GLB file and retargets it to the base character.
- Uses `SceneLoader.ImportAnimationsAsync()` to import animations
- Finds "Unreal Take" animation group (or falls back to last group)
- Calls `retargetAnimWithBlendshapes()` to map animations to base character
- Extracts frame range and calculates duration
- Starts animation paused for seeking capability

### `seekAnimation(time)`
Seeks the animation to a specific time in seconds.
- Converts time to frame number based on frame rate
- Starts and pauses animation if not already started (required for `goToFrame()` to work)
- Calls `currentAnimationGroup.goToFrame(targetFrame)`

### `setCanvasTime(time, who)`
Sets the current playback time and updates UI.
- Clamps time within valid duration
- Calls `seekAnimation()`
- Updates subtitle track and timeline arrow

---

## Retargeting Functions

### `retargetAnimWithBlendshapes(animGroup, cloneName)`
Core function that retargets animations from a source GLB to the base character skeleton and morph targets.

**Process:**
1. Clones the animation group with a target remapping callback
2. For each animation target:
   - First tries to match bone by name in skeleton
   - If not found, tries normalized name (strips prefixes like `Armature|` or `mixamorig:`)
   - If still not found, tries to match morph target by name
3. Returns the cloned animation group with remapped targets

**Bone Matching:**
```javascript
let boneIdx = skeleton.getBoneIndexByName(target.name);
if (boneIdx !== -1) {
    return skeleton.bones[boneIdx]._linkedTransformNode;
}
```

**Morph Target Matching:**
```javascript
morphIndex = getMorphTargetIndex(morphTargetManagers[curMTM], target.name);
if (morphIndex !== -1) {
    return morphTargetManagers[curMTM].getTarget(morphIndex);
}
```

### `normalizeBoneName(name)`
Normalizes bone names to handle different naming conventions.
- Strips `Armature|` prefix (Blender exports)
- Strips `mixamorig:` prefix (Mixamo exports)

### `getMorphTargetIndex(morphTargetManager, targetName)`
Finds a morph target by name within a morph target manager.
- Iterates through all targets in the manager
- Returns index if found, -1 if not found

---

## Annotation/Timeline Functions

### Timeline State
- `subtitles[]` - Array of subtitle objects with `{id, text, start, end, tier, committed}`
- `TIER_HEIGHT = 80` - Height of each tier in pixels
- `pixelsPerSecond = 500` - Timeline zoom level
- 3 tiers: Nederlands (0), Gebaar-voor-Gebaar (1), Signbank ID glossen (2)

### `renderAll()`
Renders all subtitle boxes on the timeline.
- Creates tier background labels
- For each subtitle, creates a draggable/resizable box with:
  - Drag handle for moving
  - Left/right resize handles
  - Editable content area
  - Delete and loop buttons
  - Glossary dropdown (for tier 2)

### `timeToPx(time)` / `pxToTime(px)`
Converts between time (seconds) and pixel position on timeline.

### `updateTimelineWidth()`
Updates timeline track width based on animation duration and zoom level.

### `renderTimeRuler()`
Renders the time ruler with tick marks at 0.2s intervals.

### Drag & Resize Functions

#### `initDrag(e, id)` / `onDrag(e)` / `endDrag()`
Handles dragging subtitle boxes to new positions/tiers.

#### `initLeftResize(e, id)` / `onLeftResize(e)` / `endLeftResize()`
Handles resizing subtitle start time by dragging left edge.

#### `initRightResize(e, id)` / `onRightResize(e)` / `endRightResize()`
Handles resizing subtitle end time by dragging right edge.

### `timelineAddClick(e)`
Handles adding new subtitles via timeline click.
- First click sets start time
- Second click sets end time and creates subtitle

### `deleteSubtitle(id)`
Removes a subtitle from the list.

### `syncTwin(updatedSub)`
Syncs paired subtitles in tiers 1 and 2 when sync mode is enabled.

---

## Subtitle Persistence Functions

### `fetchSubtitlesFromServer()`
Loads existing subtitles from server via `getZinnen.php?action=fetchSubtitles`.

### `uploadSubtitles()`
Triggers autosave with 1-second debounce.

### `performUpload()`
Saves subtitles to server via `getZinnen.php?action=saveSubtitlesAndEAFFiles`.

### `downloadSubtitle()`
Downloads subtitles as WebVTT files (one per tier).

### `transformSubtitles(rawSubtitles)`
Transforms server subtitle format to internal format.

### `generateWebVTT()`
Generates WebVTT format string from subtitles array.

---

## Playback Functions

### Play Button Handler
Toggles playback with configurable speed.
- Supports loop-on-hover mode for subtitle preview
- Supports button-activated loop for specific subtitle

### Speed Controls
- 0.25x, 0.5x, 0.75x, 1x playback speeds
- Keyboard shortcuts: 1, 2, 3, 4

### Zoom Controls
- +/- buttons adjust `pixelsPerSecond`
- Keyboard shortcuts: +, -

---

## Preview Functions

### `captureSceneToCanvas(canvasCtx, time)`
Captures the 3D scene at a specific time to a 2D canvas (for start/end frame preview).

### `updateThreeCanvases(sub)`
Shows side-by-side preview of subtitle start and end frames.

### `showVideoPreview(element, videoUrl)` / `hideVideoPreview()`
Shows/hides Signbank video preview for glossary items.

---

## Glossary Functions

### `filterSensesList(searchTerm, container)`
Filters glosses based on Dutch senses.

### `showSuggestions(container)`
Shows gloss suggestions based on current sentence words.

### `filterGlosses(filterId, filterValue, container)`
Filters glosses by hand shape, handedness, location, movement.

### `displayGlosses(matches, container, targetElement)`
Renders filtered gloss results with video preview on hover.

---

## Key Global Variables

| Variable | Description |
|----------|-------------|
| `engine` | Babylon.js engine instance |
| `scene` | Babylon.js scene |
| `character` | Loaded base character mesh result |
| `skeleton` | Base character skeleton (67 bones) |
| `morphTargetManagers` | Array of 9 morph target managers |
| `currentAnimationGroup` | Currently loaded/retargeted animation |
| `animationFromFrame` | Start frame of animation |
| `animationToFrame` | End frame of animation |
| `animationDuration` | Duration in seconds |
| `animationFrameRate` | Frame rate (default 30) |
| `subtitles` | Array of subtitle objects |
| `glosses` | Loaded glossary data |

---

## URL Parameters

- `?glb=/path/to/animation.glb` - Auto-loads animation on page load
- `?filename=example.wav` - Links subtitles to specific audio file
