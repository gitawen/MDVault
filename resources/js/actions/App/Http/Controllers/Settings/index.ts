import GeneralController from './GeneralController'
import StorageController from './StorageController'
import EditorController from './EditorController'
import AppearanceController from './AppearanceController'
const Settings = {
    GeneralController: Object.assign(GeneralController, GeneralController),
StorageController: Object.assign(StorageController, StorageController),
EditorController: Object.assign(EditorController, EditorController),
AppearanceController: Object.assign(AppearanceController, AppearanceController),
}

export default Settings