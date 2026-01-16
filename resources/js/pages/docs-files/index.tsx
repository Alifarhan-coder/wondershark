import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { useState, useEffect, useRef } from 'react';

import HeadingSmall from '@/components/heading-small';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { FileText, FolderOpen, Upload, Download, CirclePlus, Ellipsis,ArrowUpFromLine,Folder,Trash2,Archive,SquarePen,Copy,Redo, MoreVertical } from 'lucide-react';
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuPortal,
  DropdownMenuSeparator,
  DropdownMenuShortcut,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Input } from "@/components/ui/input";
import { router, usePage } from '@inertiajs/react';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from "@/components/ui/dialog";
import { toast } from 'sonner';

type FileModel = {
    id: number;
    name: string;
    original_name: string;
    path: string;
    mime_type: string;
    size: number;
    size_for_humans: string;
    folder: string | null;
    url: string;
    user: {
        name: string;
    };
    brand: {
        name: string;
    } | null;
    created_at: string;
};

type FolderModel = {
    id: number;
    name: string;
    parent: string | null;
    user: {
        name: string;
    };
    brand: {
        name: string;
    } | null;
};

type Brand = {
    id: number;
    name: string;
};

type Props = {
    title: string;
    files: FileModel[];
    folders: FolderModel[];
    allFolders: FolderModel[];
    brands: Brand[];
    currentBrand?: number;
    currentFolder?: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Docs & Files',
        href: '/docs-files',
    },
];

/**
 * Get all descendant folder paths for a given folder
 */
function getDescendantFolders(folders: FolderModel[], parentId: number | null, targetPath: string | null): Set<string> {
    const descendants = new Set<string>();
    
    function collectDescendants(path: string) {
        folders.forEach(folder => {
            if (folder.parent === path) {
                const folderPath = folder.name;
                const fullPath = path ? `${path}/${folderPath}` : folderPath;
                descendants.add(fullPath);
                collectDescendants(fullPath);
            }
        });
    }
    
    if (targetPath) {
        collectDescendants(targetPath);
    }
    
    return descendants;
}

export default function DocsFilesIndex({ title, files, folders, allFolders, brands, currentBrand, currentFolder }: Props) {
    const folderParts = currentFolder ? currentFolder.split('/') : [];
    const dynamicBreadcrumbs: BreadcrumbItem[] = [
        {
            title: 'Docs & Files',
            href: '/docs-files',
        },
        ...folderParts.map((part, index) => ({
            title: part,
            href: '/docs-files?folder=' + folderParts.slice(0, index + 1).join('/')
        }))
    ];
    const [uploading, setUploading] = useState(false);
    const [dragOverFolder, setDragOverFolder] = useState<string | null>(null);
    const [showMoveFolderModal, setShowMoveFolderModal] = useState(false);
    const [showCopyFolderModal, setShowCopyFolderModal] = useState(false);
    const [selectedFolder, setSelectedFolder] = useState<FolderModel | null>(null);
    const [showMoveFileModal, setShowMoveFileModal] = useState(false);
    const [showCopyFileModal, setShowCopyFileModal] = useState(false);
    const [selectedFile, setSelectedFile] = useState<FileModel | null>(null);
    const [showMoveModal, setShowMoveModal] = useState(false);
    const [showCopyModal, setShowCopyModal] = useState(false);
    const [isDragging, setIsDragging] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);
    const { data, setData, post, processing, errors } = useForm({
        file: null as File | null,
        brand_id: currentBrand?.toString() || '',
        folder: currentFolder || '',
    });

    const handleFileSelect = (event: React.ChangeEvent<HTMLInputElement>) => {
        const file = event.target.files?.[0];
        if (file) {
            setData('file', file);
        }
    };

    const handleUpload = () => {
        if (!data.file) return;

        const formData = new FormData();
        formData.append('file', data.file);
        if (data.brand_id) formData.append('brand_id', data.brand_id);
        if (data.folder) formData.append('folder', data.folder);

        setUploading(true);
        router.post('/docs-files', formData, {
            forceFormData: true,
            onSuccess: () => {
                setData('file', null);
                if (fileInputRef.current) {
                    fileInputRef.current.value = '';
                }
                setUploading(false);
            },
            onError: () => {
                setUploading(false);
            },
        });
    };

    const handleBrandChange = (brandId: string) => {
        router.get(
            '/docs-files',
            brandId === 'all' ? {} : { brand_id: brandId },
            { preserveState: true }
        );
    };

    const handleDelete = (file: FileModel) => {
        if (confirm('Are you sure you want to delete this file?')) {
            router.delete(`/docs-files/${file.id}`);
        }
    };

    const handleCreateFolder = () => {
        const folderName = window.prompt('Enter folder name:');
        if (folderName) {
            router.post('/docs-files/folders', { name: folderName, parent: currentFolder || '', brand_id: data.brand_id || null });
        }
    };

    const handleUploadFilesClick = () => {
        fileInputRef.current?.click();
    };

    const handleMove = (file: FileModel) => {
        setSelectedFile(file);
        setShowMoveFileModal(true);
    };
    const handleCopy = (file: FileModel) => {
        setSelectedFile(file);
        setShowCopyFileModal(true);
    };

    const handleRenameFolder = (folder: FolderModel) => {
        const newName = window.prompt('Enter new folder name:', folder.name);
        if (newName && newName !== folder.name) {
            router.put(`/docs-files/folders/${folder.id}`, { name: newName }, {
                onSuccess: () => router.reload()
            });
        }
    };

    const handleMoveFolder = (folder: FolderModel) => {
        setSelectedFolder(folder);
        setShowMoveFolderModal(true);
    };

    const handleCopyFolder = (folder: FolderModel) => {
        setSelectedFolder(folder);
        setShowCopyFolderModal(true);
    };

    const handleDeleteFolder = (folder: FolderModel) => {
        if (confirm('Are you sure you want to delete this folder and all its contents?')) {
            router.delete(`/docs-files/folders/${folder.id}`, {
                onSuccess: () => router.reload()
            });
        }
    };

    const handleDownloadFolder = (folder: FolderModel) => {
        // Create a temporary link and trigger download
        const link = document.createElement('a');
        link.href = `/docs-files/folders/${folder.id}/download`;
        link.download = `${folder.name}.zip`;
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };

    const handleDownloadFile = (file: FileModel) => {
        window.open(file.url, '_blank');
    };

    const handleDrop = (data: string, folder: FolderModel) => {
        const [type, id] = data.split(':');
        const dest = folder.parent ? `${folder.parent}/${folder.name}` : folder.name;
        if (type === 'file') {
            router.put(`/docs-files/${id}/move`, { folder: dest });
        } else if (type === 'folder') {
            router.put(`/docs-files/folders/${id}/move`, { parent: dest });
        }
    };

    const getFileIcon = (mimeType: string) => {
        if (mimeType.startsWith('image/')) return '🖼️';
        if (mimeType.startsWith('video/')) return '🎥';
        if (mimeType === 'application/pdf') return '📄';
        if (mimeType.includes('document') || mimeType.includes('word')) return '📝';
        if (mimeType.includes('spreadsheet') || mimeType.includes('excel')) return '📊';
        return '📄';
    };
    return (
        <AppLayout breadcrumbs={dynamicBreadcrumbs}>
            <Head title={title} />

            <div className="space-y-6">
                <div className="flex items-center justify-between">
                    <HeadingSmall
                        title="Docs & Files"
                        description="Manage your documents and files"
                    />
                </div>
                {currentFolder && (
                    <div className="text-sm text-muted-foreground">
                        <a href="/docs-files" className="text-blue-600 hover:underline">Docs & Files</a>
                        {folderParts.map((part, index) => (
                            <span key={index}>
                                {' > '}
                                <a href={`/docs-files?folder=${folderParts.slice(0, index + 1).join('/')}`} className="text-blue-600 hover:underline">
                                    {part}
                                </a>
                            </span>
                        ))}
                    </div>
                )}
                <div className="flex items-center justify-between">
                    <div>
                        <label htmlFor="brand-select" className="text-sm font-medium">Brand</label>
                        <Select value={currentBrand?.toString() || 'all'} onValueChange={handleBrandChange}>
                            <SelectTrigger className="w-[180px]">
                                <SelectValue placeholder="All Brands" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Brands</SelectItem>
                                {brands.map((brand) => (
                                    <SelectItem key={brand.id} value={brand.id.toString()}>
                                        {brand.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="flex gap-3">
                        {/* <Button variant="outline">
                            <Download className="h-4 w-4 mr-2" />
                            Export
                        </Button> */}
                        <label htmlFor="file-upload">
                            <Button asChild>
                                <span>
                                    <Upload className="h-4 w-4 mr-2" />
                                    Upload File
                                </span>
                            </Button>
                        </label>
                        <Input
                            ref={fileInputRef}
                            id="file-upload"
                            type="file"
                            className="hidden"
                            onChange={handleFileSelect}
                            accept="*/*"
                        />
                        {data.file && (
                            <Button onClick={handleUpload} disabled={uploading}>
                                {uploading ? 'Uploading...' : 'Upload'}
                            </Button>
                        )}
                    </div>
                </div>

                <Card>
                    <CardHeader className='flex justify-between flex-row items-center'>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild className='rounded-full primary-btn btn-sm border-0'>
                                <Button variant="outline"><CirclePlus/> New ...</Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent className="max-w-40" align="start">
                                <DropdownMenuItem onClick={handleCreateFolder}><Folder className="mr-2 px-5 py-3 flex items-center gap-3 text-white" /> Make a New Folder</DropdownMenuItem>
                                <DropdownMenuItem onClick={handleUploadFilesClick} className='px-5 py-3 flex items-center gap-3'><ArrowUpFromLine/> Upload Files</DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                        <CardTitle className='font-bold'>
                            Docs & Files
                        </CardTitle>
                        {/* <DropdownMenu>
                            <DropdownMenuTrigger asChild className='rounded-full h-10 w-10'>
                                <Button variant="outline"><Ellipsis/></Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent className="max-w-40" style={{ background:"#FF5B49"}} align="start">
                                <DropdownMenuLabel className='px-5 py-3 flex items-center gap-3 text-white'><Redo/> Move</DropdownMenuLabel>
                                <DropdownMenuLabel className='px-5 py-3 flex items-center gap-3 text-white'><Copy/> Copy</DropdownMenuLabel>
                                <DropdownMenuLabel className='px-5 py-3 flex items-center gap-3 text-white'><SquarePen/> Rename this folder</DropdownMenuLabel>
                                <DropdownMenuLabel className='px-5 py-3 flex items-center gap-3 text-white'><Archive/> Archive</DropdownMenuLabel>
                                <DropdownMenuLabel className='px-5 py-3 flex items-center gap-3 text-white'><Trash2/> Put in The Trash</DropdownMenuLabel>
                            </DropdownMenuContent>
                        </DropdownMenu> */}
                    </CardHeader>
                    <hr/>
                    <CardContent>
                        {files.length > 0 || folders.length > 0 ? (
                            <div className="space-y-4">
                                {folders.map((folder) => {
                                    const fullPath = folder.parent ? `${folder.parent}/${folder.name}` : folder.name;
                                    return (
                                        <div 
                                            key={folder.id} 
                                            className={`flex items-center justify-between p-4 border rounded-lg cursor-pointer hover:bg-gray-50 ${dragOverFolder === folder.id.toString() ? 'bg-blue-50 border-blue-300' : ''}`} 
                                            onClick={() => !isDragging && router.get('/docs-files', { folder: fullPath })}
                                            draggable={true}
                                            onDragStart={(e) => {
                                                setIsDragging(true);
                                                e.dataTransfer.setData('text/plain', 'folder:' + folder.id.toString());
                                            }}
                                            onDragEnd={() => setIsDragging(false)}
                                            onDragOver={(e) => e.preventDefault()}
                                            onDragEnter={() => setDragOverFolder(folder.id.toString())}
                                            onDragLeave={() => setDragOverFolder(null)}
                                            onDrop={(e) => {
                                                const data = e.dataTransfer.getData('text/plain');
                                                handleDrop(data, folder);
                                                setDragOverFolder(null);
                                            }}
                                        >
                                            <div className="flex items-center gap-3">
                                                <span className="text-2xl">📁</span>
                                                <div>
                                                    <p className="font-medium">{folder.name}</p>
                                                </div>
                                            </div>
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button variant="ghost" size="sm" onClick={(e) => e.stopPropagation()}>
                                                        <MoreVertical className="h-4 w-4" />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent className="max-w-40" style={{ background:"#FF5B49"}} align="start">
                                                    <DropdownMenuItem onClick={(e) => { e.stopPropagation(); handleRenameFolder(folder); }}className='px-5 py-3 flex items-center gap-3 text-white'>
                                                        <SquarePen/>
                                                        Rename
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem onClick={(e) => { e.stopPropagation(); handleMoveFolder(folder); }}className='px-5 py-3 flex items-center gap-3 text-white'>
                                                        <Redo/>
                                                        Move
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem onClick={(e) => { e.stopPropagation(); handleCopyFolder(folder); }}className='px-5 py-3 flex items-center gap-3 text-white'>
                                                        <Copy/>
                                                        Copy
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem onClick={(e) => { e.stopPropagation(); handleDeleteFolder(folder); }}className='px-5 py-3 flex items-center gap-3 text-white'>
                                                        <Trash2/>
                                                        Delete
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem onClick={(e) => { e.stopPropagation(); handleDownloadFolder(folder); }}className='px-5 py-3 flex items-center gap-3 text-white'>
                                                        <Download/>
                                                        Download 
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </div>
                                    );
                                })}
                                {files.map((file) => (
                                    <div 
                                        key={file.id} 
                                        className="flex items-center justify-between p-4 border rounded-lg"
                                        draggable={true}
                                        onDragStart={(e) => {
                                            e.dataTransfer.setData('text/plain', 'file:' + file.id.toString());
                                        }}
                                    >
                                        <div className="flex items-center gap-3">
                                            <span className="text-2xl">{getFileIcon(file.mime_type)}</span>
                                            <div>
                                                <p className="font-medium">{file.original_name}</p>
                                                <p className="text-sm text-muted-foreground">
                                                    {file.size_for_humans} • Uploaded by {file.user.name} • {new Date(file.created_at).toLocaleDateString()}
                                                </p>
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            <Button variant="outline" size="sm" asChild>
                                                <a href={file.url} target="_blank" rel="noopener noreferrer">
                                                    <Download className="h-4 w-4" />
                                                </a>
                                            </Button>
                                            <DropdownMenu>
                                                <DropdownMenuTrigger asChild>
                                                    <Button variant="ghost" size="sm">
                                                        <MoreVertical className="h-4 w-4" />
                                                    </Button>
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent className="max-w-40" style={{ background:"#FF5B49"}} align="start">
                                                    <DropdownMenuItem onClick={() => handleMove(file)} className='px-5 py-3 flex items-center gap-3 text-white'><Redo/>
                                                        Move to Folder
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem onClick={() => handleCopy(file)}  className='px-5 py-3 flex items-center gap-3 text-white'><Copy/>
                                                        copy
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem onClick={() => handleDelete(file)}className='px-5 py-3 flex items-center gap-3 text-white'><Trash2/>
                                                        Move to Trash
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem onClick={() => handleDownloadFile(file)}className='px-5 py-3 flex items-center gap-3 text-white'><Download/>
                                                        Download File
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="text-center py-12 text-muted-foreground">
                                <FileText className="h-20 w-20 mx-auto mb-4 opacity-50" />
                                <h3 className="text-lg font-semibold mb-2">No files uploaded</h3>
                                <p className="text-sm mb-4">
                                    Start by uploading your documents, images, or other files.
                                </p>
                                <label htmlFor="file-upload-empty">
                                    <Button asChild>
                                        <span>
                                            <Upload className="h-4 w-4 mr-2" />
                                            Upload Your First File
                                        </span>
                                    </Button>
                                </label>
                                <Input
                                    id="file-upload-empty"
                                    type="file"
                                    className="hidden"
                                    onChange={handleFileSelect}
                                    accept="*/*"
                                />
                                {data.file && (
                                    <div className="mt-4">
                                        <p className="text-sm">Selected: {data.file.name}</p>
                                        <Button onClick={handleUpload} disabled={uploading} className="mt-2">
                                            {uploading ? 'Uploading...' : 'Upload File'}
                                        </Button>
                                    </div>
                                )}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            <Dialog open={showMoveFileModal} onOpenChange={setShowMoveFileModal}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Move File to Folder</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4">
                        <p>                                                                       r:</p>
                        <select
                            className="border border-gray-300 rounded-md px-3 py-2 w-full"
                            onChange={(e) => {
                                const value = e.target.value;
                                if (selectedFile) {
                                    router.put(`/docs-files/${selectedFile.id}/move`, { folder: value }, {
                                        onSuccess: () => router.reload()
                                    });
                                    setShowMoveModal(false);
                                    setSelectedFile(null);
                                }
                            }}
                        >
                            <option value="">Root</option>
                            {allFolders.map((folder) => {
                                const path = folder.parent ? `${folder.parent}/${folder.name}` : folder.name;
                                return (
                                    <option key={folder.id} value={path}>
                                        {path}
                                    </option>
                                );
                            })}
                        </select>
                    </div>
                </DialogContent>
            </Dialog>
            <Dialog open={showCopyFileModal} onOpenChange={setShowCopyFileModal}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Copy File to Folder</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4">
                        <p>Select a destination folder:</p>
                        <select
                            className="border border-gray-300 rounded-md px-3 py-2 w-full"
                            onChange={(e) => { 
                                const value = e.target.value;
                                if (selectedFile) {
                                    router.post(`/docs-files/${selectedFile.id}/copy`, { folder: value }, {
                                        onSuccess: () => router.reload()
                                    });
                                    setShowCopyModal(false);
                                    setSelectedFile(null);
                                }
                            }}
                        >
                            <option value="">Root</option>
                            {allFolders.map((folder) => {
                                const path = folder.parent ? `${folder.parent}/${folder.name}` : folder.name;
                                return (
                                    <option key={folder.id} value={path}>
                                        {path}
                                    </option>
                                );
                            })}
                        </select>
                    </div>
                </DialogContent>
            </Dialog>
            <Dialog open={showMoveFolderModal} onOpenChange={setShowMoveFolderModal}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Move Folder to Folder</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4">
                        <p>Select a destination folder:</p>
                        <select
                            className="border border-gray-300 rounded-md px-3 py-2 w-full"
                            onChange={(e) => {
                                const value = e.target.value;
                                if (selectedFolder) {
                                    router.put(`/docs-files/folders/${selectedFolder.id}/move`, { parent: value }, {
                                        onSuccess: () => {
                                            setShowMoveFolderModal(false);
                                            setSelectedFolder(null);
                                            router.reload();
                                        },
                                        onError: (errors: any) => {
                                            toast.error(errors.folder || 'Cannot move folder to this location.');
                                        }
                                    });
                                }
                            }}
                        >
                            <option value="">Root</option>
                            {allFolders
                                .filter(f => {
                                    // Exclude the folder itself
                                    if (f.id === selectedFolder?.id) {
                                        return false;
                                    }
                                    
                                    // Exclude descendants of the selected folder
                                    const selectedFolderPath = selectedFolder
                                        ? (selectedFolder.parent 
                                            ? `${selectedFolder.parent}/${selectedFolder.name}` 
                                            : selectedFolder.name)
                                        : null;
                                    const descendants = getDescendantFolders(allFolders, selectedFolder?.id || null, selectedFolderPath);
                                    
                                    const folderPath = f.parent ? `${f.parent}/${f.name}` : f.name;
                                    return !descendants.has(folderPath);
                                })
                                .map((folder) => {
                                    const path = folder.parent ? `${folder.parent}/${folder.name}` : folder.name;
                                    return (
                                        <option key={folder.id} value={path}>
                                            {path}
                                        </option>
                                    );
                                })}
                        </select>
                    </div>
                </DialogContent>
            </Dialog>
            <Dialog open={showCopyFolderModal} onOpenChange={setShowCopyFolderModal}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Copy Folder to Folder</DialogTitle>
                    </DialogHeader>
                    <div className="space-y-4">
                        <p>Select a destination folder:</p>
                        <select
                            className="border border-gray-300 rounded-md px-3 py-2 w-full"
                            onChange={(e) => {
                                const value = e.target.value;
                                if (selectedFolder) {
                                    router.post(`/docs-files/folders/${selectedFolder.id}/copy`, { parent: value }, {
                                        onSuccess: () => router.reload()
                                    });
                                    setShowCopyFolderModal(false);
                                    setSelectedFolder(null);
                                }
                            }}
                        >
                            <option value="">Root</option>
                            {allFolders.filter(f => f.id !== selectedFolder?.id).map((folder) => {
                                const path = folder.parent ? `${folder.parent}/${folder.name}` : folder.name;
                                return (
                                    <option key={folder.id} value={path}>
                                        {path}
                                    </option>
                                );
                            })}
                        </select>
                    </div>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
