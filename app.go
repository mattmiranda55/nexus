package main

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"os"
	"os/exec"
	"path/filepath"
	goruntime "runtime"
	"strings"
	"sync"
	"time"

	"github.com/wailsapp/wails/v2/pkg/runtime"
)

// App struct
type App struct {
	ctx      context.Context
	projects []Project
	settings Settings
	mu       sync.RWMutex
	logMu    sync.Mutex
	logStop  context.CancelFunc
}

// Project represents a Laravel project
type Project struct {
	ID   string `json:"id"`
	Name string `json:"name"`
	Path string `json:"path"`
}

// Settings represents user preferences
type Settings struct {
	Theme   string `json:"theme"`
	PHPPath string `json:"phpPath"`
}

// NewApp creates a new App application struct
func NewApp() *App {
	return &App{
		projects: []Project{},
		settings: Settings{
			Theme:   "dark",
			PHPPath: "",
		},
	}
}

// startup is called when the app starts
func (a *App) startup(ctx context.Context) {
	a.ctx = ctx
	log.Println("[Startup] App starting...")
	a.loadProjects()
	a.loadSettings()
	log.Printf("[Startup] Loaded %d projects", len(a.projects))
}

// getConfigPath returns the path to the config file
func (a *App) getConfigPath() string {
	homeDir, _ := os.UserHomeDir()
	configDir := filepath.Join(homeDir, ".pulsar")
	os.MkdirAll(configDir, 0755)
	return filepath.Join(configDir, "projects.json")
}

// loadProjects loads projects from config file
func (a *App) loadProjects() {
	configPath := a.getConfigPath()
	log.Printf("[LoadProjects] Reading from: %s", configPath)
	data, err := os.ReadFile(configPath)
	if err != nil {
		log.Printf("[LoadProjects] Error reading config: %v", err)
		return
	}
	if err := json.Unmarshal(data, &a.projects); err != nil {
		log.Printf("[LoadProjects] Error parsing JSON: %v", err)
	}
}

// saveProjects saves projects to config file
func (a *App) saveProjects() {
	configPath := a.getConfigPath()
	data, err := json.MarshalIndent(a.projects, "", "  ")
	if err != nil {
		log.Printf("[SaveProjects] Error marshaling JSON: %v", err)
		return
	}
	if err := os.WriteFile(configPath, data, 0644); err != nil {
		log.Printf("[SaveProjects] Error writing config: %v", err)
		return
	}
	log.Printf("[SaveProjects] Saved %d projects to: %s", len(a.projects), configPath)
}

func (a *App) getSettingsPath() string {
	homeDir, _ := os.UserHomeDir()
	configDir := filepath.Join(homeDir, ".pulsar")
	os.MkdirAll(configDir, 0755)
	return filepath.Join(configDir, "settings.json")
}

func (a *App) loadSettings() {
	settingsPath := a.getSettingsPath()
	data, err := os.ReadFile(settingsPath)
	if err != nil {
		log.Printf("[LoadSettings] No settings file yet, using defaults: %v", err)
		return
	}
	var settings Settings
	if err := json.Unmarshal(data, &settings); err != nil {
		log.Printf("[LoadSettings] Error parsing settings: %v", err)
		return
	}
	a.mu.Lock()
	a.settings = settings
	a.mu.Unlock()
	log.Printf("[LoadSettings] Loaded settings: %+v", settings)
}

func (a *App) saveSettings() {
	settingsPath := a.getSettingsPath()
	data, err := json.MarshalIndent(a.settings, "", "  ")
	if err != nil {
		log.Printf("[SaveSettings] Error marshaling settings: %v", err)
		return
	}
	if err := os.WriteFile(settingsPath, data, 0644); err != nil {
		log.Printf("[SaveSettings] Error writing settings: %v", err)
		return
	}
	log.Printf("[SaveSettings] Saved settings to: %s", settingsPath)
}

// GetProjects returns all saved projects
func (a *App) GetProjects() []Project {
	a.mu.RLock()
	defer a.mu.RUnlock()
	return a.projects
}

// GetSettings returns current settings
func (a *App) GetSettings() Settings {
	a.mu.RLock()
	defer a.mu.RUnlock()
	return a.settings
}

// UpdateSettings persists settings and updates runtime values
func (a *App) UpdateSettings(settings Settings) {
	log.Printf("[UpdateSettings] Received settings: %+v", settings)

	// Normalize theme
	if settings.Theme != "dark" && settings.Theme != "light" {
		settings.Theme = "dark"
	}
	settings.PHPPath = strings.TrimSpace(settings.PHPPath)

	a.mu.Lock()
	a.settings = settings
	a.mu.Unlock()

	// Surface current theme to the frontend (for native menus, etc.)
	runtime.EventsEmit(a.ctx, "settings:theme", settings.Theme)

	a.saveSettings()
}

// SelectDirectory opens a directory picker dialog
func (a *App) SelectDirectory() (string, error) {
	log.Println("[SelectDirectory] Opening directory picker...")
	path, err := runtime.OpenDirectoryDialog(a.ctx, runtime.OpenDialogOptions{
		Title: "Select Laravel Project",
	})
	if err != nil {
		log.Printf("[SelectDirectory] Error: %v", err)
		return "", err
	}
	log.Printf("[SelectDirectory] Selected: %s", path)
	return path, nil
}

// AddProject adds a new Laravel project
func (a *App) AddProject(name, path string) (Project, error) {
	log.Printf("[AddProject] Adding project: name=%s, path=%s", name, path)

	// Validate it's a Laravel project
	artisanPath := filepath.Join(path, "artisan")
	log.Printf("[AddProject] Checking for artisan at: %s", artisanPath)
	if _, err := os.Stat(artisanPath); os.IsNotExist(err) {
		log.Printf("[AddProject] Error: artisan not found at %s", artisanPath)
		return Project{}, fmt.Errorf("not a valid Laravel project: artisan file not found")
	}

	a.mu.Lock()
	defer a.mu.Unlock()

	project := Project{
		ID:   fmt.Sprintf("%d", time.Now().UnixNano()),
		Name: name,
		Path: path,
	}
	a.projects = append(a.projects, project)
	a.saveProjects()
	log.Printf("[AddProject] Successfully added project: %+v", project)
	return project, nil
}

// RemoveProject removes a project by ID
func (a *App) RemoveProject(id string) {
	log.Printf("[RemoveProject] Removing project with ID: %s", id)
	a.mu.Lock()
	defer a.mu.Unlock()

	for i, p := range a.projects {
		if p.ID == id {
			log.Printf("[RemoveProject] Found project: %+v", p)
			a.projects = append(a.projects[:i], a.projects[i+1:]...)
			break
		}
	}
	a.saveProjects()
	log.Printf("[RemoveProject] Project removed, %d projects remaining", len(a.projects))
}

// StartLogTail begins tailing the Laravel log file for the given project path.
// It streams new lines to the frontend via Wails events.
func (a *App) StartLogTail(projectPath string) (string, error) {
	log.Printf("[StartLogTail] Starting log tail for project: %s", projectPath)

	if projectPath == "" {
		return "", fmt.Errorf("project path is required")
	}

	logFilePath := filepath.Join(projectPath, "storage", "logs", "laravel.log")
	if err := os.MkdirAll(filepath.Dir(logFilePath), 0755); err != nil {
		return "", fmt.Errorf("unable to prepare log directory: %w", err)
	}

	// Stop any existing tail
	a.logMu.Lock()
	if a.logStop != nil {
		log.Println("[StartLogTail] Stopping existing log tail")
		a.logStop()
		a.logStop = nil
	}

	ctx, cancel := context.WithCancel(context.Background())
	a.logStop = cancel
	a.logMu.Unlock()

	initial, err := a.readLogTail(logFilePath, 200)
	if err != nil {
		log.Printf("[StartLogTail] Error reading existing logs: %v", err)
		a.StopLogTail()
		return "", fmt.Errorf("unable to read log file: %w", err)
	}

	go a.streamLogFile(ctx, logFilePath)

	return initial, nil
}

// StopLogTail stops the active log tailing session.
func (a *App) StopLogTail() {
	a.logMu.Lock()
	defer a.logMu.Unlock()

	if a.logStop != nil {
		log.Println("[StopLogTail] Stopping log tail")
		a.logStop()
		a.logStop = nil
	}
}

// readLogTail returns the last maxLines from the log file to seed the viewer.
func (a *App) readLogTail(path string, maxLines int) (string, error) {
	file, err := os.OpenFile(path, os.O_RDONLY|os.O_CREATE, 0644)
	if err != nil {
		return "", err
	}
	defer file.Close()

	var lines []string
	scanner := bufio.NewScanner(file)

	for scanner.Scan() {
		lines = append(lines, scanner.Text())
		if len(lines) > maxLines {
			lines = lines[1:]
		}
	}

	if err := scanner.Err(); err != nil {
		return "", err
	}

	return strings.Join(lines, "\n"), nil
}

// streamLogFile follows the log file similar to `tail -f` and emits updates.
func (a *App) streamLogFile(ctx context.Context, path string) {
	log.Printf("[LogTail] Streaming log file: %s", path)

	file, err := os.OpenFile(path, os.O_RDONLY|os.O_CREATE, 0644)
	if err != nil {
		log.Printf("[LogTail] Error opening log file: %v", err)
		runtime.EventsEmit(a.ctx, "log:error", fmt.Sprintf("Unable to open log file: %v", err))
		return
	}
	defer file.Close()

	if _, err := file.Seek(0, io.SeekEnd); err != nil {
		log.Printf("[LogTail] Error seeking to end: %v", err)
		runtime.EventsEmit(a.ctx, "log:error", fmt.Sprintf("Unable to read log file: %v", err))
		return
	}

	reader := bufio.NewReader(file)

	for {
		select {
		case <-ctx.Done():
			log.Println("[LogTail] Log tail cancelled")
			return
		default:
			line, err := reader.ReadString('\n')
			if err != nil {
				if errors.Is(err, io.EOF) {
					time.Sleep(300 * time.Millisecond)
					continue
				}

				log.Printf("[LogTail] Error reading log file: %v", err)
				runtime.EventsEmit(a.ctx, "log:error", fmt.Sprintf("Error reading log file: %v", err))
				return
			}

			cleaned := strings.TrimRight(line, "\r\n")
			runtime.EventsEmit(a.ctx, "log:update", cleaned)
		}
	}
}

// RunTinker executes code through php artisan tinker
func (a *App) RunTinker(projectPath, code string) string {
	log.Printf("[RunTinker] Starting execution for project: %s", projectPath)
	log.Printf("[RunTinker] Code to execute:\n%s", code)

	ctx, cancel := context.WithTimeout(context.Background(), 60*time.Second)
	defer cancel()

	// Verify project path
	artisanPath := filepath.Join(projectPath, "artisan")
	if _, err := os.Stat(artisanPath); os.IsNotExist(err) {
		log.Printf("[RunTinker] Error: Invalid project path, artisan not found at %s", artisanPath)
		return "Error: Invalid Laravel project path"
	}

	phpPath, err := a.resolvePHPBinary(projectPath)
	if err != nil {
		log.Printf("[RunTinker] Error resolving PHP binary: %v", err)
		return fmt.Sprintf("Error: %s", err)
	}
	log.Printf("[RunTinker] Using PHP binary: %s", phpPath)

	// Clean code (remove <?php tag as tinker doesn't need it)
	cleanCode := strings.TrimPrefix(strings.TrimSpace(code), "<?php")
	cleanCode = strings.TrimSpace(cleanCode)
	log.Printf("[RunTinker] Cleaned code: %s", cleanCode)

	cmd := exec.CommandContext(ctx, phpPath, "artisan", "tinker")
	cmd.Dir = projectPath

	stdin, err := cmd.StdinPipe()
	if err != nil {
		log.Printf("[RunTinker] Error opening stdin: %v", err)
		return fmt.Sprintf("Error: %s", err)
	}

	go func() {
		defer stdin.Close()
		io.WriteString(stdin, cleanCode+"\n")
	}()

	output, err := cmd.CombinedOutput()
	log.Printf("[RunTinker] Command output: %s, err: %v", string(output), err)

	if ctx.Err() == context.DeadlineExceeded {
		log.Println("[RunTinker] Error: Execution timed out")
		return "Error: Execution timed out (60s limit)"
	}

	// Parse and clean output
	var result strings.Builder
	lines := strings.Split(string(output), "\n")
	log.Printf("[RunTinker] Processing %d lines", len(lines))
	for i, line := range lines {
		trimmed := strings.TrimSpace(line)
		log.Printf("[RunTinker] Line %d: raw=%q trimmed=%q", i, line, trimmed)

		// Strip leading prompt characters (> or .)
		cleaned := trimmed
		for strings.HasPrefix(cleaned, "> ") || strings.HasPrefix(cleaned, ". ") {
			cleaned = strings.TrimPrefix(cleaned, "> ")
			cleaned = strings.TrimPrefix(cleaned, ". ")
			cleaned = strings.TrimSpace(cleaned)
		}

		// Skip empty, shell info, and echoed code lines
		if cleaned == "" || cleaned == "." ||
			strings.Contains(cleaned, "Psy Shell") ||
			cleaned == "exit" {
			log.Printf("[RunTinker] Skipping line")
			continue
		}

		// Check if this is a result line (starts with "= ")
		if strings.HasPrefix(cleaned, "= ") {
			val := strings.TrimPrefix(cleaned, "= ")
			log.Printf("[RunTinker] Found result: %s", val)
			result.WriteString(val + "\n")
		} else if !strings.HasPrefix(trimmed, "> ") && !strings.HasPrefix(trimmed, ". ") {
			// Keep non-prompt output (like dump() output)
			log.Printf("[RunTinker] Keeping output: %s", cleaned)
			result.WriteString(cleaned + "\n")
		}
	}

	finalResult := strings.TrimSpace(result.String())
	if finalResult == "" {
		log.Println("[RunTinker] Result is empty, returning 'null'")
		return "null"
	}
	log.Printf("[RunTinker] Final result: %s", finalResult)
	return finalResult
}

// RunTinkerStreaming runs tinker with streaming output (for future use)
func (a *App) RunTinkerStreaming(projectPath, code string) string {
	ctx, cancel := context.WithTimeout(context.Background(), 60*time.Second)
	defer cancel()

	phpPath, err := a.resolvePHPBinary(projectPath)
	if err != nil {
		return fmt.Sprintf("Error: %s", err)
	}
	log.Printf("[RunTinkerStreaming] Using PHP binary: %s", phpPath)

	cmd := exec.CommandContext(ctx, phpPath, "artisan", "tinker")
	cmd.Dir = projectPath

	stdin, _ := cmd.StdinPipe()
	stdout, _ := cmd.StdoutPipe()
	stderr, _ := cmd.StderrPipe()

	if err := cmd.Start(); err != nil {
		return fmt.Sprintf("Error: %s", err)
	}

	// Write code
	cleanCode := strings.TrimPrefix(strings.TrimSpace(code), "<?php")
	io.WriteString(stdin, strings.TrimSpace(cleanCode)+"\n")
	io.WriteString(stdin, "exit\n")
	stdin.Close()

	var result strings.Builder
	scanner := bufio.NewScanner(io.MultiReader(stdout, stderr))
	for scanner.Scan() {
		line := scanner.Text()
		// Filter tinker prompt noise
		if !strings.HasPrefix(line, ">>>") && !strings.HasPrefix(line, "...") {
			result.WriteString(line + "\n")
		}
	}

	cmd.Wait()
	return strings.TrimSpace(result.String())
}

// resolvePHPBinary tries to locate the PHP executable closest to the project (Herd/local first).
func (a *App) resolvePHPBinary(projectPath string) (string, error) {
	var candidates []string

	a.mu.RLock()
	currentSettings := a.settings
	a.mu.RUnlock()

	// 1) Project-local shims (Herd preferred)
	candidates = append(candidates,
		filepath.Join(projectPath, ".herd", "bin", "php"),
		filepath.Join(projectPath, ".config", "herd", "bin", "php"),
	)

	// 2) Project vendor-provided php (non-Herd)
	candidates = append(candidates, filepath.Join(projectPath, "vendor", "bin", "php"))

	// 3) User-level Herd installation (macOS/Linux default)
	if homeDir, err := os.UserHomeDir(); err == nil {
		candidates = append(candidates, filepath.Join(homeDir, ".config", "herd", "bin", "php"))
	}

	switch goruntime.GOOS {
	case "darwin":
		// 4) macOS Herd app bundle path
		candidates = append(candidates, "/Applications/Herd.app/Contents/Resources/bin/php")
	case "windows":
		// 4) Windows Herd default locations (best-effort)
		if localAppData := os.Getenv("LOCALAPPDATA"); localAppData != "" {
			candidates = append(candidates, filepath.Join(localAppData, "Herd", "bin", "php.exe"))
		}
		candidates = append(candidates, `C:\Program Files\Herd\bin\php.exe`)
	}

	// 5) Explicit overrides (for non-Herd projects or specific versions)
	if currentSettings.PHPPath != "" {
		candidates = append(candidates, currentSettings.PHPPath)
	}
	if envPath := strings.TrimSpace(os.Getenv("PULSAR_PHP_PATH")); envPath != "" {
		candidates = append(candidates, envPath)
	}

	// 6) Fallback to PATH if nothing else is found
	if pathLookup, err := exec.LookPath("php"); err == nil {
		candidates = append(candidates, pathLookup)
	}

	for _, candidate := range candidates {
		if candidate == "" {
			continue
		}
		if info, err := os.Stat(candidate); err == nil && !info.IsDir() {
			return candidate, nil
		}
	}

	return "", fmt.Errorf("unable to find PHP executable; set PULSAR_PHP_PATH or configure an explicit binary in Settings")
}
