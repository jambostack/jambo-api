import { useState, useCallback, useEffect, useRef } from 'react';
import { useTranslation } from '@/lib/i18n';
import { useDropzone } from 'react-dropzone';
import axios from 'axios';
import { cn } from '@/lib/utils';

import {
	Dialog,
	DialogContent,
	DialogHeader,
	DialogTitle,
	DialogFooter,
	DialogDescription,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Progress } from '@/components/ui/progress';
import { ScrollArea } from '@/components/ui/scroll-area';
import {
	Upload,
	X,
	CheckCircle,
	AlertCircle,
	File as FileIcon,
	ImageIcon,
	FileTextIcon,
	FileVideoIcon,
	FileAudioIcon,
	StopCircle,
} from 'lucide-react';


interface AssetUploaderProps {
	isOpen: boolean;
	onClose: () => void;
	projectId: number;
	projectUuid: string;
	onUploadComplete: () => void;
	folderId?: number | null;
}

type UploadStatus = 'pending' | 'uploading' | 'completed' | 'error' | 'cancelled';

interface UploadFile {
	id: string;
	file: File;
	progress: number;
	error: string | null;
	completed: boolean;
	status: UploadStatus;
}

export default function AssetUploader({ isOpen, onClose, projectId, projectUuid, onUploadComplete, folderId }: AssetUploaderProps) {
	const t = useTranslation();
	const [files, setFiles] = useState<UploadFile[]>([]);
	const [uploading, setUploading] = useState(false);
	const [overallProgress, setOverallProgress] = useState(0);
	const [currentUploadingId, setCurrentUploadingId] = useState<string | null>(null);

	const isStoppedRef = useRef(false);
	const uploadingRef = useRef(false);

	const fileRefs = useRef<{ [key: string]: HTMLDivElement | null }>({});
	const scrollAreaRef = useRef<HTMLDivElement>(null);

	const onDrop = useCallback((acceptedFiles: File[]) => {
		const newFiles = acceptedFiles.map(file => ({
			id: Math.random().toString(36).substring(2, 9),
			file,
			progress: 0,
			error: null,
			completed: false,
			status: 'pending' as UploadStatus,
		}));
		setFiles(prevFiles => [...prevFiles, ...newFiles]);
	}, []);

	const { getRootProps, getInputProps, isDragActive } = useDropzone({
		onDrop,
		accept: {
			'image/*': [],
			'video/*': [],
			'audio/*': [],
			'application/pdf': [],
			'application/msword': [],
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document': [],
			'application/vnd.ms-excel': [],
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': [],
			'application/vnd.ms-powerpoint': [],
			'application/vnd.openxmlformats-officedocument.presentationml.presentation': [],
			'text/plain': [],
		}
	});

	const removeFile = (id: string) => {
		setFiles(prevFiles => prevFiles.filter(file => file.id !== id));
	};

	const updateFileProgress = (id: string, progress: number) => {
		setFiles(prevFiles => {
			const updatedFiles = prevFiles.map(file =>
				file.id === id ? { ...file, progress } : file
			);

			const filesToCount = updatedFiles.filter(f =>
				f.status === 'pending' || f.status === 'uploading' || f.status === 'completed'
			);

			if (filesToCount.length > 0) {
				const totalProgress = filesToCount.reduce((acc, file) => {
					if (file.status === 'pending') return acc;
					if (file.status === 'completed') return acc + 100;
					return acc + file.progress;
				}, 0);
				setOverallProgress(totalProgress / filesToCount.length);
			}

			return updatedFiles;
		});
	};

	const updateFileStatus = (id: string, status: UploadStatus, error: string | null = null) => {
		setFiles(prevFiles => prevFiles.map(file =>
			file.id === id ? { ...file, status, completed: status === 'completed', error } : file
		));
	};

	useEffect(() => {
		if (currentUploadingId && fileRefs.current[currentUploadingId] && scrollAreaRef.current) {
			fileRefs.current[currentUploadingId].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		}
	}, [currentUploadingId]);

	const stopUploads = () => {
		isStoppedRef.current = true;
		setFiles(prevFiles =>
			prevFiles.map(file =>
				file.status === 'uploading' || file.status === 'pending'
					? { ...file, status: 'cancelled', error: 'Upload cancelled' }
					: file
			)
		);
		setUploading(false);
		uploadingRef.current = false;
		setCurrentUploadingId(null);
	};

	const uploadFiles = async () => {
		if (uploadingRef.current) return;

		isStoppedRef.current = false;
		setUploading(true);
		uploadingRef.current = true;

		try {
			const pendingFiles = files.filter(file => file.status === 'pending');

			for (let i = 0; i < pendingFiles.length; i++) {
				if (isStoppedRef.current) break;

				const fileItem = pendingFiles[i];
				setCurrentUploadingId(fileItem.id);
				updateFileStatus(fileItem.id, 'uploading');

				const formData = new FormData();
				formData.append('file', fileItem.file);
				if (folderId != null) {
					formData.append('folder_id', String(folderId));
				}

				try {
					await axios.post(`/api/projects/${projectUuid}/media`, formData, {
						headers: { 'Content-Type': 'multipart/form-data' },
						onUploadProgress: (progressEvent) => {
							if (isStoppedRef.current) return;
							if (progressEvent.total) {
								const progress = Math.round((progressEvent.loaded / progressEvent.total) * 100);
								updateFileProgress(fileItem.id, progress);
							}
						},
					});

					if (isStoppedRef.current) {
						updateFileStatus(fileItem.id, 'cancelled');
						break;
					}

					updateFileProgress(fileItem.id, 100);
					updateFileStatus(fileItem.id, 'completed');

				} catch (error: any) {
					let errorMessage = t('assets.uploader_failed_default');

					if (error.response) {
						if (error.response.data?.message) {
							errorMessage = error.response.data.message;
							if (error.response.data.errors?.file) {
								const fileErrors = error.response.data.errors.file;
								if (Array.isArray(fileErrors) && fileErrors.length > 0) {
									errorMessage += `: ${fileErrors.join(', ')}`;
								}
							}
							if (error.response.data.file_size_limit) {
								errorMessage += ` (Max size: ${error.response.data.file_size_limit})`;
							}
						} else if (error.response.status === 413) {
							errorMessage = t('assets.uploader_too_large');
						}
					}

					if (isStoppedRef.current) {
						updateFileStatus(fileItem.id, 'cancelled');
						break;
					} else {
						updateFileStatus(fileItem.id, 'error', errorMessage);
					}
				}
			}
		} finally {
			setUploading(false);
			uploadingRef.current = false;
			setCurrentUploadingId(null);
			onUploadComplete();
		}
	};

	useEffect(() => {
		if (!isOpen) {
			setFiles([]);
			setOverallProgress(0);
			setCurrentUploadingId(null);
			isStoppedRef.current = true;
			uploadingRef.current = false;
		}
	}, [isOpen]);

	const handleClose = () => {
		if (uploading) return;
		onClose();
	};

	const allCompleted = files.length > 0 && files.every(file =>
		file.status === 'completed' || file.status === 'cancelled' || file.status === 'error'
	);

	const hasActiveUploads = files.some(file =>
		file.status === 'uploading' || file.status === 'pending'
	);

	const getFileIcon = (file: File) => {
		const type = file.type;
		if (type.startsWith('image/')) return <ImageIcon className="h-4 w-4 text-sky-500" />;
		if (type.startsWith('video/')) return <FileVideoIcon className="h-4 w-4 text-violet-500" />;
		if (type.startsWith('audio/')) return <FileAudioIcon className="h-4 w-4 text-emerald-500" />;
		if (type.includes('pdf') || type.includes('document') || type.includes('text')) {
			return <FileTextIcon className="h-4 w-4 text-amber-500" />;
		}
		return <FileIcon className="h-4 w-4 text-muted-foreground" />;
	};

	const formatFileSize = (bytes: number) => {
		if (bytes < 1024) return bytes + ' B';
		if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
		return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
	};

	const getStatusDisplay = (file: UploadFile) => {
		switch (file.status) {
			case 'uploading':
				return file.progress < 100 ? t('assets.uploader_status_uploading') : t('assets.uploader_status_processing');
			case 'completed':
				return t('assets.uploader_status_completed');
			case 'error':
				return t('assets.uploader_status_failed');
			case 'cancelled':
				return t('assets.uploader_status_cancelled');
			default:
				return t('assets.uploader_status_pending');
		}
	};

	const pendingCount = files.filter(f => f.status === 'pending').length;
	const completedCount = files.filter(f => f.status === 'completed').length;
	const errorCount = files.filter(f => f.status === 'error').length;

	return (
		<Dialog open={isOpen} onOpenChange={handleClose}>
			<DialogContent className="sm:max-w-lg max-h-[90vh] flex flex-col overflow-y-auto">
				<DialogHeader>
					<DialogTitle>{t('assets.uploader_title')}</DialogTitle>
					<DialogDescription>{t('assets.uploader_desc')}</DialogDescription>
				</DialogHeader>

				{/* Drop zone — hidden while uploading */}
				{!uploading && (
					<div
						{...getRootProps()}
						className={cn(
							'relative mt-2 flex cursor-pointer flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed px-6 py-10 text-center transition-all',
							isDragActive
								? 'border-primary bg-primary/5 scale-[1.01]'
								: 'border-border/60 bg-muted/30 hover:border-border hover:bg-muted/50'
						)}
					>
						<input {...getInputProps()} />
						<div className={cn(
							'flex h-14 w-14 items-center justify-center rounded-2xl transition-all',
							isDragActive ? 'bg-primary/10' : 'bg-background shadow-sm'
						)}>
							<Upload className={cn(
								'h-6 w-6 transition-colors',
								isDragActive ? 'text-primary' : 'text-muted-foreground'
							)} />
						</div>
						<div>
							<p className="text-sm font-medium text-foreground">
								{isDragActive ? t('assets.uploader_drop') : t('assets.uploader_drag')}
							</p>
							<p className="mt-1 text-xs text-muted-foreground">{t('assets.uploader_types')}</p>
						</div>
					</div>
				)}

				{/* File list */}
				{files.length > 0 && (
					<div className="mt-4 space-y-3">
						{/* Stats bar */}
						<div className="flex items-center justify-between text-xs">
							<div className="flex items-center gap-3">
								<span className="font-medium text-muted-foreground">
									{t('assets.uploader_files_selected', { count: String(files.length) })}
								</span>
								{completedCount > 0 && (
									<span className="flex items-center gap-1 text-emerald-600 dark:text-emerald-400 font-medium">
										<CheckCircle className="h-3 w-3" />
										{completedCount}
									</span>
								)}
								{errorCount > 0 && (
									<span className="flex items-center gap-1 text-destructive font-medium">
										<AlertCircle className="h-3 w-3" />
										{errorCount}
									</span>
								)}
							</div>
							{allCompleted && (
								<span className="text-emerald-600 dark:text-emerald-400 font-medium">
									{t('assets.uploader_complete')}
								</span>
							)}
						</div>

						{/* Overall progress */}
						{uploading && (
							<div className="space-y-1.5">
								<div className="flex items-center justify-between text-xs text-muted-foreground">
									<span className="font-medium">
										{overallProgress < 100 ? t('assets.uploader_uploading') : t('assets.uploader_processing')}
									</span>
									<span className="tabular-nums font-medium">{Math.round(overallProgress)}%</span>
								</div>
								<Progress
									value={overallProgress}
									className={cn(
										'h-1.5',
										overallProgress === 100 && 'bg-emerald-100 dark:bg-emerald-900/30 [&>div]:bg-emerald-500'
									)}
								/>
								{hasActiveUploads && (
									<div className="flex justify-center pt-1">
										<Button
											size="sm"
											variant="outline"
											className="h-7 gap-1.5 text-xs text-destructive hover:text-destructive"
											onClick={stopUploads}
										>
											<StopCircle className="h-3.5 w-3.5" />
											{t('assets.uploader_stop')}
										</Button>
									</div>
								)}
							</div>
						)}

						{/* File rows */}
						<div className="rounded-xl border border-border/60 overflow-hidden">
							<ScrollArea className="max-h-[220px]" ref={scrollAreaRef}>
								<div className="divide-y divide-border/60">
									{files.map(file => (
										<div
											key={file.id}
											ref={(el) => { fileRefs.current[file.id] = el; }}
											className={cn(
												'flex items-center gap-3 px-3 py-2.5 transition-colors',
												file.status === 'error' && 'bg-destructive/5',
												file.status === 'cancelled' && 'bg-muted/40',
												file.status === 'completed' && 'bg-emerald-50/50 dark:bg-emerald-950/10',
												currentUploadingId === file.id && 'bg-primary/5'
											)}
										>
											{/* Icon */}
											<div className="shrink-0">
												{getFileIcon(file.file)}
											</div>

											{/* File info */}
											<div className="flex-1 min-w-0">
												<p className="truncate text-sm font-medium" title={file.file.name}>
													{file.file.name}
												</p>
												<div className="flex items-center gap-2 mt-0.5">
													<span className="font-mono text-[11px] text-muted-foreground tabular-nums">
														{formatFileSize(file.file.size)}
													</span>
													{file.status === 'error' && file.error && (
														<span className="text-[11px] text-destructive truncate">{file.error}</span>
													)}
												</div>
											</div>

											{/* Status / action */}
											<div className="shrink-0 flex items-center">
												{file.status === 'completed' ? (
													<CheckCircle className="h-4 w-4 text-emerald-500" />
												) : file.status === 'error' || file.status === 'cancelled' ? (
													<Button
														variant="ghost"
														size="sm"
														className="h-6 px-2 text-[11px] text-muted-foreground hover:text-destructive"
														onClick={(e) => { e.stopPropagation(); removeFile(file.id); }}
													>
														{t('assets.uploader_remove')}
													</Button>
												) : file.status === 'uploading' ? (
													<div className="w-24">
														<div className="flex justify-between items-center text-[11px] mb-1 text-muted-foreground">
															<span className="truncate">{getStatusDisplay(file)}</span>
															<span className="ms-1 tabular-nums">{file.progress}%</span>
														</div>
														<Progress
															value={file.progress}
															className={cn(
																'h-1',
																file.progress === 100 && 'bg-emerald-100 dark:bg-emerald-900/30 [&>div]:bg-emerald-500'
															)}
														/>
													</div>
												) : (
													<Button
														variant="ghost"
														size="icon"
														className="h-6 w-6 text-muted-foreground hover:text-foreground"
														onClick={(e) => { e.stopPropagation(); removeFile(file.id); }}
													>
														<X className="h-3.5 w-3.5" />
														<span className="sr-only">Remove file</span>
													</Button>
												)}
											</div>
										</div>
									))}
								</div>
							</ScrollArea>
						</div>
					</div>
				)}

				<DialogFooter className="gap-2 mt-4">
					<Button variant="outline" onClick={handleClose} disabled={uploading}>
						{allCompleted ? t('assets.uploader_close') : t('assets.uploader_cancel')}
					</Button>

					{!allCompleted && !uploading && (
						<Button
							onClick={uploadFiles}
							disabled={files.length === 0 || !files.some(file => file.status === 'pending')}
							className="gap-2"
						>
							<Upload className="h-3.5 w-3.5" />
							{t('assets.uploader_upload')}
							{pendingCount > 0 && (
								<span className="ms-0.5 opacity-70">({pendingCount})</span>
							)}
						</Button>
					)}
				</DialogFooter>
			</DialogContent>
		</Dialog>
	);
}
