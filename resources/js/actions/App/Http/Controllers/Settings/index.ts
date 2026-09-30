import GeneralController from './GeneralController'
import StorageController from './StorageController'
import EditorController from './EditorController'
import AppearanceController from './AppearanceController'
import BackupController from './BackupController'
import BackupRestoreController from './BackupRestoreController'
import DatabaseResetController from './DatabaseResetController'
const Settings = {
    GeneralController: Object.assign(GeneralController, GeneralController),
StorageController: Object.assign(StorageController, StorageController),
EditorController: Object.assign(EditorController, EditorController),
AppearanceController: Object.assign(AppearanceController, AppearanceController),
BackupController: Object.assign(BackupController, BackupController),
BackupRestoreController: Object.assign(BackupRestoreController, BackupRestoreController),
DatabaseResetController: Object.assign(DatabaseResetController, DatabaseResetController),
}

export default Settings